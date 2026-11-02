# 🤖 机器人插件 API（v1.18）

> 站点：`https://你的域名/?bot_api=1`　｜　鉴权：`token` 参数或 `X-Bot-Token` 请求头
> Token 在「配置管理 → 📣 群机器人 → 机器人插件接口」查看，可轮换（旧 token 立即失效）。

## 1. 通用约定

- 成功：`{"ok":true,"data":{...}}`
- 失败：`{"ok":false,"error":"..."}`，HTTP 4xx/5xx
- GET 参数/表单/POST JSON 都支持；参数优先取 GET。
- 绑定关系存 `bot_bindings`；通知存 `bot_notifications`。
- 旧版 `bot_bindings.json` 在首次读取时自动迁移到数据库，原文件可保留/删除。

## 2. 端点

| action | 参数 | 说明 |
|---|---|---|
| `ping` | — | 探活，返回 `version=1.18.0` |
| `roster` | — | 花名册姓名列表 |
| `status` | `name` | 某学生应缴/已缴/待缴/未缴轮次 |
| `unpaid` | `limit` | 未缴名单 |
| `summary` | — | 全班汇总 |
| `bind` | `user`, `name`, `channel`(默认 wechat) | 远端用户绑定花名册学生（存 DB） |
| `whoami` | `user`, `channel` | 查询绑定 |
| `paylink` | `user`, `channel` | 专属缴费链接，URL 含 `?pay=<student_id>#page-pay` |
| `pay` / `pay_create` | `user`, `round_id`, `channel_type`(或 `type`), `channel_id` | 为已绑定学生创建在线支付订单，返回 order_no/payurl/qrcode |
| `poll` / `notifications` | `after_id`, `limit`, `status` | 拉取通知；默认 `status=pending`；按 id 升序 |
| `ack` / `ack_notifications` | `ids`(逗号或 JSON), `ack_status=sent\|failed` | 确认插件已处理，幂等 |
| `notify` | `title`, `content` | 插件写入一条群通知（可用于插件主动公告） |
| `help` | — | 返回动作列表 |

### 绑定示例

```bash
curl 'https://域名/?bot_api=1&action=bind&token=TOKEN&channel=wechat&user=wx_openid_123&name=张三'
curl 'https://域名/?bot_api=1&action=whoami&token=TOKEN&channel=wechat&user=wx_openid_123'
```

### 通知轮询示例

```bash
curl 'https://域名/?bot_api=1&action=poll&token=TOKEN&after_id=0&limit=20'
# data.items[] = {id,event,title,content,payload,status,push_status,created_at,...}
curl 'https://域名/?bot_api=1&action=ack&token=TOKEN&ids=12,13&ack_status=sent'
```

## 3. 通知事件

| event | 触发 | 关键 payload |
|---|---|---|
| `payment_success` | 支付回调/手动核销 | order_no, student_id, student_name, amount, channel |
| `amount_change` | 新增/修改/删除收支 | action, tx_id, type, before_amount, after_amount, description, actor |
| `plugin_notify` | 插件 `notify` | source |

- `status`：插件 ack 状态（pending/sent/failed）。
- `push_status`：站点是否已代推群（pending/sent/failed）；插件可据 `push_status=sent` 跳过重复推送。
- 站点代推使用「配置管理 → 群机器人」已配置的 QQ/企业微信/飞书/钉钉/自定义 Webhook；没有可用通道时保持 pending，不阻塞业务。
- 通知幂等键：支付按订单号、金额变动按账目 id + 动作 + 内容 hash；重复回调不会重复入队。

## 4. 缴费页自动选学生

- `?pay=<student_id>`、`?student=<student_id>`、`#page-pay` 都会打开缴费页并自动选中对应学生。
- 已登录学生（非班委）打开缴费页默认自动选中本人，且 `pay_meta.roster` 只返回本人。
- 机器人 `paylink` 统一返回 `?pay=...`，与 QQ 机器人「我的链接」一致。

## 5. 验收清单

- [x] 新库自动迁移 `bot_bindings` / `bot_notifications`，旧库升级不丢数据
- [x] `bind/whoami` 持久化到 DB，未知姓名 404，错误 token 401
- [x] `paylink` 返回 `?pay=<id>#page-pay`
- [x] 缴费页对 `?pay/?student` 与登录学生本人自动选中
- [x] 支付成功（自动/手动核销）各只写 1 条 `payment_success`
- [x] 新增/修改金额/删除账目写 `amount_change`，重复保存不重复入队
- [x] `poll/ack` 游标、幂等、插件 ack 状态正确
- [x] 配置群机器人后，金额变动/支付通知可被站点直接推送（`push_status=sent`）
- [x] `notify` 可写入插件通知并被 poll 到
- [x] 全量 PHP/JS lint、MySQL 集成测试与发布 CI 通过

## 6. 对接提示

1. 插件启动时 `ping`，保存当前 `next_id`（首次可 0）。
2. 定时 `poll?after_id=next_id&limit=20`，成功处理后 `ack`，更新游标。
3. 通知 `push_status=sent` 表示站点已代推群；若插件也要推，请跳过，避免重复。
4. 插件 Token 轮换后，旧 Token 立即失效；请在后台先复制新 Token 再更新插件。
