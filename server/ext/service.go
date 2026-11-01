// Package ext 是 nps-admin-ext 扩展模块的服务端实现（DESIGN.md §5）。
// 全部为纯新增代码：不修改任何现有 controller / proxy / bridge 逻辑，
// 仅复用现有数据源与鉴权（web/controllers.ExtController 继承 BaseController）。
package ext

import (
	"errors"
	"os"
	"runtime"
	"syscall"
	"time"

	"github.com/astaxie/beego/logs"

	"github.com/kardianos/service"
)

// ExtVersion 扩展模块版本，集中管理端据此做能力探测。
// 0.3.0：新增内置 ACME（HTTP-01）签发端点；0.2.0 为 edit 语义与备份加固
const ExtVersion = "0.3.0"

var serviceInstance service.Service

// SetServiceInstance 由 cmd/nps 在服务启动时注入系统服务实例
// （cmd/nps/nps.go 的 nps.Start 中一行调用，纯新增）。
func SetServiceInstance(s service.Service) {
	serviceInstance = s
}

// TriggerRestart 延迟 delay 触发重启，保证 HTTP 响应先于进程终止发出。
// 优先走 service.Control("restart")（systemd/init.d 通道，环境一致）；
// 注意 kardianos 在 systemd 平台总能创建 service 对象——即使服务
// 未安装，Control 会失败（exit status 1），此时回退 execve 自重启。
// Windows 下无法自重启，返回错误提示手动操作。
func TriggerRestart(delay time.Duration) error {
	if serviceInstance != nil {
		go func() {
			time.Sleep(delay)
			if err := service.Control(serviceInstance, "restart"); err != nil {
				logs.Warn("ext: service restart error %s, fallback to execve", err.Error())
				execveRestart()
			}
		}()
		return nil
	}
	if runtime.GOOS == "windows" {
		return errors.New("no service instance registered, please restart manually")
	}
	go func() {
		time.Sleep(delay)
		execveRestart()
	}()
	return nil
}

// execveRestart Linux 交互模式自重启：进程镜像原地替换，
// 监听 socket 由新进程重新建立
func execveRestart() {
	if runtime.GOOS == "windows" {
		return
	}
	if err := syscall.Exec(os.Args[0], os.Args, os.Environ()); err != nil {
		logs.Error("ext: execve restart error %s", err.Error())
	}
}
