package controllers

import (
	"ehang.io/nps/lib/changelog"
	"ehang.io/nps/lib/common"
	"ehang.io/nps/lib/file"
	"ehang.io/nps/lib/rate"
	"ehang.io/nps/server"
	"github.com/astaxie/beego"
)

type ClientController struct {
	BaseController
}

func (s *ClientController) List() {
	if s.Ctx.Request.Method == "GET" {
		s.Data["menu"] = "client"
		s.SetInfo("client")
		s.display("client/list")
		return
	}
	start, length := s.GetAjaxParams()
	clientIdSession := s.GetSession("clientId")
	var clientId int
	if clientIdSession == nil {
		clientId = 0
	} else {
		clientId = clientIdSession.(int)
	}
	list, cnt := server.GetClientList(start, length, s.getEscapeString("search"), s.getEscapeString("sort"), s.getEscapeString("order"), clientId)
	cmd := make(map[string]interface{})
	ip := s.Ctx.Request.Host
	cmd["ip"] = common.GetIpByAddr(ip)
	cmd["bridgeType"] = beego.AppConfig.String("bridge_type")
	cmd["bridgePort"] = server.Bridge.TunnelPort
	s.AjaxTable(list, cnt, cnt, cmd)
}

//添加客户端
func (s *ClientController) Add() {
	if s.Ctx.Request.Method == "GET" {
		s.Data["menu"] = "client"
		s.SetInfo("add client")
		s.display()
	} else {
		t := &file.Client{
			VerifyKey: s.getEscapeString("vkey"),
			Id:        int(file.GetDb().JsonDb.GetClientId()),
			Status:    true,
			Remark:    s.getEscapeString("remark"),
			Cnf: &file.Config{
				U:        s.getEscapeString("u"),
				P:        s.getEscapeString("p"),
				Compress: common.GetBoolByStr(s.getEscapeString("compress")),
				Crypt:    s.GetBoolNoErr("crypt"),
			},
			ConfigConnAllow: s.GetBoolNoErr("config_conn_allow"),
			RateLimit:       s.GetIntNoErr("rate_limit"),
			MaxConn:         s.GetIntNoErr("max_conn"),
			WebUserName:     s.getEscapeString("web_username"),
			WebPassword:     s.getEscapeString("web_password"),
			MaxTunnelNum:    s.GetIntNoErr("max_tunnel"),
			Flow: &file.Flow{
				ExportFlow: 0,
				InletFlow:  0,
				FlowLimit:  int64(s.GetIntNoErr("flow_limit")),
			},
		}
		if err := file.GetDb().NewClient(t); err != nil {
			s.AjaxErr(err.Error())
		}
		s.AjaxOk("add success")
	}
}
func (s *ClientController) GetClient() {
	if s.Ctx.Request.Method == "POST" {
		id := s.GetIntNoErr("id")
		data := make(map[string]interface{})
		if c, err := file.GetDb().GetClient(id); err != nil {
			data["code"] = 0
		} else {
			data["code"] = 1
			data["data"] = c
		}
		s.Data["json"] = data
		s.ServeJSON()
	}
}

//修改客户端
func (s *ClientController) Edit() {
	id := s.GetIntNoErr("id")
	if s.Ctx.Request.Method == "GET" {
		s.Data["menu"] = "client"
		if c, err := file.GetDb().GetClient(id); err != nil {
			s.error()
		} else {
			s.Data["c"] = c
		}
		s.SetInfo("edit client")
		s.display()
	} else {
		if c, err := file.GetDb().GetClient(id); err != nil {
			s.error()
			s.AjaxErr("client ID not found")
			return
		} else {
			if s.getEscapeString("web_username") != "" {
				if s.getEscapeString("web_username") == beego.AppConfig.String("web_username") || !file.GetDb().VerifyUserName(s.getEscapeString("web_username"), c.Id) {
					s.AjaxErr("web login username duplicate, please reset")
					return
				}
			}
			if s.GetSession("isAdmin").(bool) {
				if !file.GetDb().VerifyVkey(s.getEscapeString("vkey"), c.Id) {
					s.AjaxErr("Vkey duplicate, please reset")
					return
				}
				// nps-admin-ext 补丁（AUDIT H2）：以下字段仅在被显式提交时修改。
				// 原实现为全量替换语义，集中管理端的部分更新（如超额限流只传
				// rate_limit）会把未传字段清零：flow_limit/max_conn 归 0、
				// vkey 置空、Basic 认证与门户密码被清空
				if s.hasParam("vkey") {
					c.VerifyKey = s.getEscapeString("vkey")
				}
				if s.hasParam("flow_limit") {
					c.Flow.FlowLimit = int64(s.GetIntNoErr("flow_limit"))
				}
				if s.hasParam("rate_limit") {
					c.RateLimit = s.GetIntNoErr("rate_limit")
				}
				if s.hasParam("max_conn") {
					c.MaxConn = s.GetIntNoErr("max_conn")
				}
				if s.hasParam("max_tunnel") {
					c.MaxTunnelNum = s.GetIntNoErr("max_tunnel")
				}
			}
			if s.hasParam("remark") {
				c.Remark = s.getEscapeString("remark")
			}
			if s.hasParam("u") {
				c.Cnf.U = s.getEscapeString("u")
			}
			if s.hasParam("p") {
				c.Cnf.P = s.getEscapeString("p")
			}
			if s.hasParam("compress") {
				c.Cnf.Compress = common.GetBoolByStr(s.getEscapeString("compress"))
			}
			if s.hasParam("crypt") {
				c.Cnf.Crypt = s.GetBoolNoErr("crypt")
			}
			b, err := beego.AppConfig.Bool("allow_user_change_username")
			if (s.GetSession("isAdmin").(bool) || (err == nil && b)) && s.hasParam("web_username") {
				c.WebUserName = s.getEscapeString("web_username")
			}
			if s.hasParam("web_password") {
				c.WebPassword = s.getEscapeString("web_password")
			}
			if s.hasParam("config_conn_allow") {
				c.ConfigConnAllow = s.GetBoolNoErr("config_conn_allow")
			}
			if c.Rate != nil {
				c.Rate.Stop()
			}
			if c.RateLimit > 0 {
				c.Rate = rate.NewRate(int64(c.RateLimit * 1024))
				c.Rate.Start()
			} else {
				c.Rate = rate.NewRate(int64(2 << 23))
				c.Rate.Start()
			}
			file.GetDb().JsonDb.StoreClientsToJsonFile()
		}
		s.AjaxOk("save success")
	}
}

//更改状态
func (s *ClientController) ChangeStatus() {
	id := s.GetIntNoErr("id")
	if client, err := file.GetDb().GetClient(id); err == nil {
		client.Status = s.GetBoolNoErr("status")
		if client.Status == false {
			server.DelClientConnect(client.Id)
		}
		changelog.Append("update", "client", client.Id)
		s.AjaxOk("modified success")
	}
	s.AjaxErr("modified fail")
}

//删除客户端
func (s *ClientController) Del() {
	id := s.GetIntNoErr("id")
	if err := file.GetDb().DelClient(id); err != nil {
		s.AjaxErr("delete error")
	}
	server.DelTunnelAndHostByClientId(id, false)
	server.DelClientConnect(id)
	s.AjaxOk("delete success")
}
