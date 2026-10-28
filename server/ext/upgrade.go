package ext

import (
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"io/ioutil"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

const (
	// maxInlineSize inline 模式（base64 直传）的大小上限；
	// 大文件请走 url 模式（从集中管理端制品库下载）
	maxInlineSize = 64 << 20
	// maxDownloadSize url 模式下载上限
	maxDownloadSize = 256 << 20
)

// UpgradeRequest 升级请求（DESIGN.md §9 /ext/upgrade POST）
type UpgradeRequest struct {
	Mode    string `json:"mode"`    // "inline"（base64 直传）或 "url"（制品库下载）
	Binary  string `json:"binary"`  // inline 模式：base64 编码的二进制
	Url     string `json:"url"`     // url 模式：制品下载地址
	Sha256  string `json:"sha256"`  // 期望 sha256（hex），必填以防传输损坏
	Version string `json:"version"` // 目标版本号（仅记录）
}

// Upgrade 校验并替换 nps 二进制。
//
// 替换机制：Linux 下不允许覆盖运行中的 ELF（ETXTBSY），但允许
// rename 替换其路径——运行中进程持有旧 inode，下次 systemd 拉起
// 使用新文件，实现原子替换。Windows 下运行中 exe 被锁，回退为
// 官方 update 同款做法：写 binPath-update，由调用方提示手动替换。
//
// 注意：官方 install.UpdateNps() 不可用于本方案（只拉 GitHub latest、
// 无版本选择、无回滚、且官方二进制不含 ext 模块）——升级一律由
// 集中管理端的自建发布流水线驱动（DESIGN.md §9.1）。
func Upgrade(req *UpgradeRequest) (map[string]interface{}, error) {
	var data []byte
	var err error
	switch req.Mode {
	case "inline":
		if req.Binary == "" {
			return nil, errors.New("empty binary")
		}
		if data, err = base64.StdEncoding.DecodeString(req.Binary); err != nil {
			return nil, fmt.Errorf("base64 decode error: %s", err.Error())
		}
		if len(data) > maxInlineSize {
			return nil, errors.New("binary too large, use url mode")
		}
	case "url":
		if req.Url == "" {
			return nil, errors.New("empty url")
		}
		if data, err = download(req.Url); err != nil {
			return nil, fmt.Errorf("download error: %s", err.Error())
		}
	default:
		return nil, errors.New("mode must be inline or url")
	}
	// sha256 校验（必填，防止传输损坏或制品被篡改）
	if req.Sha256 == "" {
		return nil, errors.New("sha256 is required")
	}
	sum := sha256.Sum256(data)
	if !strings.EqualFold(hex.EncodeToString(sum[:]), req.Sha256) {
		return nil, errors.New("sha256 mismatch")
	}

	binPath, err := os.Executable()
	if err != nil {
		return nil, err
	}
	binPath, _ = filepath.EvalSymlinks(binPath)

	res := map[string]interface{}{
		"version":     req.Version,
		"sha256":      hex.EncodeToString(sum[:]),
		"binary_path": binPath,
	}

	if runtime.GOOS == "windows" {
		// Windows：运行中 exe 不可覆盖，写 -update 文件，手动替换后重启
		newPath := binPath + "-update"
		if err = ioutil.WriteFile(newPath, data, 0755); err != nil {
			return nil, err
		}
		res["replaced"] = false
		res["note"] = "windows cannot replace a running exe, binary written to " + newPath + ", replace and restart manually"
		return res, nil
	}

	// Linux：写临时文件后 rename 原子替换（运行中进程持旧 inode）
	tmp := binPath + ".ext-upgrade.tmp"
	if err = ioutil.WriteFile(tmp, data, 0755); err != nil {
		return nil, err
	}
	if err = os.Rename(tmp, binPath); err != nil {
		_ = os.Remove(tmp)
		return nil, err
	}
	res["replaced"] = true
	res["note"] = "binary replaced, restart required to take effect"
	return res, nil
}

// download 从制品库下载二进制（出方向 HTTPS GET）
func download(url string) ([]byte, error) {
	client := &http.Client{Timeout: 10 * time.Minute}
	resp, err := client.Get(url)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("http status %d", resp.StatusCode)
	}
	return ioutil.ReadAll(io.LimitReader(resp.Body, maxDownloadSize))
}
