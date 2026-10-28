package ext

import (
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"fmt"
	"io/ioutil"
	"path/filepath"

	"ehang.io/nps/lib/common"

	"github.com/astaxie/beego"
)

// defaultCertPath / defaultKeyPath 证书默认落盘路径
//（conf 未配置 web_cert_file/web_key_file 时使用）
func defaultCertPath() string {
	return filepath.Join(common.GetRunPath(), "conf", "web_cert.pem")
}

func defaultKeyPath() string {
	return filepath.Join(common.GetRunPath(), "conf", "web_key.pem")
}

func currentCertPath() string {
	if p := beego.AppConfig.String("web_cert_file"); p != "" {
		return p
	}
	return defaultCertPath()
}

func currentKeyPath() string {
	if p := beego.AppConfig.String("web_key_file"); p != "" {
		return p
	}
	return defaultKeyPath()
}

// ApplyCert 校验并落盘证书（DESIGN.md §7 /ext/cert POST）。
// 证书激活的唯一入口：certd 部署通道与 Laravel native 通道最终都
// 调到这里，保证单一 SoT。
// 行为：校验证书与私钥匹配 → 原子写文件 → 更新 conf 键
//（web_cert_file/web_key_file/web_open_ssl=true）。
// 注意：web_open_ssl 在 beego 内存中仍是旧值，重启后生效——
// 首次启用 HTTPS 必须"先推证书、后开开关"，顺序由集中管理端编排。
func ApplyCert(certPem, keyPem []byte) (map[string]interface{}, error) {
	if len(certPem) == 0 || len(keyPem) == 0 {
		return nil, errors.New("empty cert or key pem")
	}
	// 1. 校验证书与私钥匹配（tls.X509KeyPair 完整校验签名链）
	if _, err := tls.X509KeyPair(certPem, keyPem); err != nil {
		return nil, fmt.Errorf("cert/key mismatch: %s", err.Error())
	}
	info := map[string]interface{}{}
	// 2. 解析证书元信息（到期时间、域名）
	if block, _ := pem.Decode(certPem); block != nil {
		if cert, err := x509.ParseCertificate(block.Bytes); err == nil {
			info["not_after"] = cert.NotAfter.Unix()
			info["dns_names"] = cert.DNSNames
		}
	}
	// 3. 落盘（私钥 0600）
	certPath, keyPath := currentCertPath(), currentKeyPath()
	if err := ioutil.WriteFile(certPath, certPem, 0644); err != nil {
		return nil, err
	}
	if err := ioutil.WriteFile(keyPath, keyPem, 0600); err != nil {
		return nil, err
	}
	// 4. 更新 conf 键并确保 TLS 开启
	entries := map[string]string{
		"web_cert_file": certPath,
		"web_key_file":  keyPath,
		"web_open_ssl":  "true",
	}
	if _, _, err := WriteConfigEntries(entries); err != nil {
		return nil, fmt.Errorf("write conf error: %s", err.Error())
	}
	sum := sha256.Sum256(certPem)
	info["sha256"] = hex.EncodeToString(sum[:])
	info["cert_file"] = certPath
	info["key_file"] = keyPath
	return info, nil
}

// CertFingerprint 当前证书状态（/ext/cert GET），供集中管理端每日对账：
// 校验各 nps 实际生效证书与证书库登记值一致（防 certd/Laravel 双 SoT 漂移）。
func CertFingerprint() (map[string]interface{}, error) {
	certPath := currentCertPath()
	b, err := ioutil.ReadFile(certPath)
	if err != nil {
		return nil, fmt.Errorf("read cert error: %s", err.Error())
	}
	info := map[string]interface{}{
		"cert_file": certPath,
		"key_file":  currentKeyPath(),
	}
	if block, _ := pem.Decode(b); block != nil {
		if cert, err := x509.ParseCertificate(block.Bytes); err == nil {
			info["not_after"] = cert.NotAfter.Unix()
			info["dns_names"] = cert.DNSNames
		}
	}
	sum := sha256.Sum256(b)
	info["sha256"] = hex.EncodeToString(sum[:])
	return info, nil
}
