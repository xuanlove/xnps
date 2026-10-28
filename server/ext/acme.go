package ext

import (
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"fmt"
	"io/ioutil"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"ehang.io/nps/lib/acmechallenge"
	"ehang.io/nps/lib/common"

	"golang.org/x/crypto/acme"
)

// 内置 ACME（Let's Encrypt）签发：HTTP-01 挑战由 nps 的 HTTP 监听器应答
// （lib/acmechallenge + server/proxy/http.go 的 hook）。
//
// 约束：HTTP-01 不接受通配符域名；要求 http_proxy_port 对公网可达
//（通常是 80，或由负载均衡把 80 映射到该端口）。

const (
	acmeProduction = acme.LetsEncryptURL
	acmeStaging    = "https://acme-staging-v02.api.letsencrypt.org/directory"
	acmeTimeout    = 180 * time.Second
)

var domainPattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$`)

// ACMERequest /ext/acme/issue 请求体
type ACMERequest struct {
	Domains      []string `json:"domains"`
	Email        string   `json:"email"`
	DirectoryURL string   `json:"directory_url"` // 空=LE 生产；仅允许 LE 生产/测试目录
}

// IssueACME 申请证书并返回 PEM（私钥在 nps 侧生成，随响应回传由管理端入库）
func IssueACME(req *ACMERequest) (map[string]interface{}, error) {
	domains, err := normalizeDomains(req.Domains)
	if err != nil {
		return nil, err
	}
	directory := strings.TrimSpace(req.DirectoryURL)
	if directory == "" {
		directory = acmeProduction
	}
	if directory != acmeProduction && directory != acmeStaging {
		return nil, errors.New("directory_url must be the Let's Encrypt production or staging directory")
	}
	email := strings.TrimSpace(req.Email)
	if email != "" && (!strings.Contains(email, "@") || len(email) > 254) {
		return nil, errors.New("invalid email")
	}

	accountKey, err := loadOrCreateAccountKey()
	if err != nil {
		return nil, fmt.Errorf("acme account key: %s", err.Error())
	}

	ctx, cancel := context.WithTimeout(context.Background(), acmeTimeout)
	defer cancel()

	client := &acme.Client{
		Key:          accountKey,
		DirectoryURL: directory,
		UserAgent:    "xnps-ext/" + ExtVersion,
	}
	account := &acme.Account{}
	if email != "" {
		account.Contact = []string{"mailto:" + email}
	}
	if _, err = client.Register(ctx, account, acme.AcceptTOS); err != nil {
		return nil, fmt.Errorf("acme register: %s", err.Error())
	}

	order, err := client.AuthorizeOrder(ctx, acme.DomainIDs(domains...))
	if err != nil {
		return nil, fmt.Errorf("acme new order: %s", err.Error())
	}

	tokens := make([]string, 0, len(order.AuthzURLs))
	defer func() {
		for _, token := range tokens {
			acmechallenge.Delete(token)
		}
	}()

	for _, authzURL := range order.AuthzURLs {
		authz, err := client.GetAuthorization(ctx, authzURL)
		if err != nil {
			return nil, fmt.Errorf("acme get authorization: %s", err.Error())
		}
		if authz.Status == acme.StatusValid {
			continue
		}
		ch := pickHTTP01(authz)
		if ch == nil {
			return nil, fmt.Errorf("acme no http-01 challenge for %s", authz.Identifier.Value)
		}
		keyAuth, err := client.HTTP01ChallengeResponse(ch.Token)
		if err != nil {
			return nil, fmt.Errorf("acme challenge response: %s", err.Error())
		}
		acmechallenge.Put(ch.Token, keyAuth)
		tokens = append(tokens, ch.Token)
		if _, err = client.Accept(ctx, ch); err != nil {
			return nil, fmt.Errorf("acme accept challenge: %s", err.Error())
		}
		if _, err = client.WaitAuthorization(ctx, authz.URI); err != nil {
			return nil, fmt.Errorf("acme authorization failed for %s: %s", authz.Identifier.Value, err.Error())
		}
	}

	if _, err = client.WaitOrder(ctx, order.URI); err != nil {
		return nil, fmt.Errorf("acme wait order: %s", err.Error())
	}

	certKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, err
	}
	csr, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{
		Subject:  pkix.Name{CommonName: domains[0]},
		DNSNames: domains,
	}, certKey)
	if err != nil {
		return nil, fmt.Errorf("csr: %s", err.Error())
	}

	chain, _, err := client.CreateOrderCert(ctx, order.FinalizeURL, csr, true)
	if err != nil {
		return nil, fmt.Errorf("acme finalize: %s", err.Error())
	}
	if len(chain) == 0 {
		return nil, errors.New("acme returned empty certificate chain")
	}

	var certBuf strings.Builder
	for _, der := range chain {
		certBuf.Write(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}))
	}
	keyDER, err := x509.MarshalECPrivateKey(certKey)
	if err != nil {
		return nil, err
	}
	keyPEM := pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER})
	certPEM := []byte(certBuf.String())

	res := map[string]interface{}{
		"cert_pem":      string(certPEM),
		"key_pem":       string(keyPEM),
		"directory_url": directory,
	}
	if leaf, err := x509.ParseCertificate(chain[0]); err == nil {
		res["not_after"] = leaf.NotAfter.Unix()
		res["dns_names"] = leaf.DNSNames
	}
	sum := sha256.Sum256(certPEM)
	res["sha256"] = hex.EncodeToString(sum[:])
	return res, nil
}

func normalizeDomains(input []string) ([]string, error) {
	if len(input) == 0 {
		return nil, errors.New("domains is required")
	}
	if len(input) > 10 {
		return nil, errors.New("too many domains (max 10)")
	}
	seen := make(map[string]bool)
	out := make([]string, 0, len(input))
	for _, raw := range input {
		d := strings.ToLower(strings.TrimSpace(raw))
		if strings.HasPrefix(d, "*.") {
			return nil, fmt.Errorf("wildcard domain %s is not supported by http-01", raw)
		}
		if len(d) > 253 || !domainPattern.MatchString(d) {
			return nil, fmt.Errorf("invalid domain: %s", raw)
		}
		if seen[d] {
			continue
		}
		seen[d] = true
		out = append(out, d)
	}
	if len(out) == 0 {
		return nil, errors.New("domains is required")
	}
	return out, nil
}

func pickHTTP01(authz *acme.Authorization) *acme.Challenge {
	for _, ch := range authz.Challenges {
		if ch.Type == "http-01" && (ch.Status == acme.StatusPending || ch.Status == acme.StatusProcessing) {
			return ch
		}
	}
	for _, ch := range authz.Challenges {
		if ch.Type == "http-01" {
			return ch
		}
	}
	return nil
}

func loadOrCreateAccountKey() (crypto.Signer, error) {
	path := filepath.Join(common.GetRunPath(), "conf", "acme_account.key")
	if b, err := ioutil.ReadFile(path); err == nil {
		block, _ := pem.Decode(b)
		if block == nil {
			return nil, errors.New("invalid acme account key file")
		}
		if key, err := x509.ParseECPrivateKey(block.Bytes); err == nil {
			return key, nil
		}
		if key, err := x509.ParsePKCS8PrivateKey(block.Bytes); err == nil {
			if signer, ok := key.(crypto.Signer); ok {
				return signer, nil
			}
		}
		return nil, errors.New("unsupported acme account key format")
	} else if !os.IsNotExist(err) {
		return nil, err
	}

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, err
	}
	der, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		return nil, err
	}
	pemBytes := pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: der})
	if err = ioutil.WriteFile(path, pemBytes, 0600); err != nil {
		return nil, err
	}
	return key, nil
}
