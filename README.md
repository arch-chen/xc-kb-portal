# XC知识库系统（给本地 Agent 用的知识库服务）

> **开源版本 · MIT 协议**：本仓库以 MIT 协议完整开源，代码与部署版本一致 —— 没有功能阉割、
> 没有租户限制、没有授权校验或代码加密。协议全文见 [`LICENSE`](LICENSE)。
> 零依赖 PHP + SQLite，上传解压即可运行；**部署前必读 [`DEPLOY.md`](DEPLOY.md)**（尤其 `storage/kb.sqlite` 的 nginx 屏蔽规则）。

定位先说清楚：**这是一套给本地 Agent 调用的知识库服务**，网页界面只是超管的后台——分配权限、看内容、核对版本、升级。
日常真正的使用方式是：本地 Agent 拿着 API Key，通过 HTTP 接口查 / 写 / 改这个库。

三条设计约束：

- **写入是幂等的**：同一个问题重复推只会留一条。答案没变就跳过（不动 `updated_at`），变了才覆盖，并先把旧答案存成历史版本
- **一个 Key 一个分组**：权限边界钉在 Key 上，用户的超管身份也越不过去；请求体里带 `group_id` 一律忽略
- **零依赖**：不需要 Composer、不需要框架、不需要 Node，上传解压就能跑

> **部署前先看 `DEPLOY.md`。** 装完只跑通安装向导还不算上线：`storage/kb.sqlite` 就躺在站点目录里，
> 不给 nginx 加那两行规则，任何人一条 URL 就能把整库下载走（不需要账号或 Key）。
> `DEPLOY.md` 里有逐步清单、自检命令和报错对照表；规则原文也放在 `nginx.example.conf`，可直接 `include`。

---

## 1. 它能做什么

| 角色 | 能力 |
|---|---|
| **本地 Agent** | 用 API Key 调 REST 接口：检索、读条目、**幂等批量写入**、查版本历史、增量拉取、全量导出。范围严格限制在该 Key 绑定的分组 |
| **ChatGPT / Claude** | 走 MCP 端点 `/mcp.php` 直接挂载这个知识库（或给自定义 GPT 导入 `/openapi.php`），同样是 Key 鉴权、同样的分组边界，可只读也可读写 |
| 超级管理员 | 建分组、建用户、给用户分配「某个分组能不能读/写」、查看和吊销所有 API Key、核对版本变更、上传更新包或 GitHub 一键升级、开关注册；**「网站基础信息」一处改全站身份**（名称 / 副标题 / 图标 / 页脚 / 备案号 / 联系方式，见 `DEPLOY.md` 第 17 章） |
| 普通用户 | 登录后在权限内的分组里搜索和阅读；可以自建分组（建完就是私有空间）；自己创建 API Key（绑定一个分组，读/写分开勾选）；在「账户设置」改昵称、改密码、绑定邮箱，账单、充值、AI 助手也在这一栏。左侧下方有「订阅会员」入口，**订阅后自动变成「会员用户」标识**（按钮消失） |
| **付费用户** | 用兑换码或在线支付充积分，按次调用 AI（优化答案、文本转 QA）；月付套餐可填自己的模型接口，调用不扣积分；已支付订单可申请退款，后台同意后自动退回 |
| **AI 工作台** | `?r=agent`：能动手的对话——自己抓网页、建分组、把整理好的问答/文档写进知识库，每步一张工具卡片，写错能撤销。**只回答本系统用法与你有读权限的知识库内容**，无关话题直接拒答；业务问题先检索再答，查不到就说没有 |
| **访客（自助注册）** | 打开登录页点「注册新账号」即可建号（可要求邮箱验证码），注册完自动得到一个**私有空间**，内容只有本人可见；用户之间互相搜不到 |
| **访客（未登录）** | 访问站点根地址看到**对外落地页**（`?r=landing`）：能力介绍、三步上手、三种接口说明、源码仓库入口。已登录用户访问根地址仍是工作台，习惯不变（见 `DEPLOY.md` 第 19 章） |
| **界面说明** | 表单页只留「标签 + 输入框 + 按钮」，原理、排查步骤、安全提醒都收进文字旁的**小问号**：鼠标悬停 / 键盘 Tab / 手机点一下才展开。报错状态和要照抄的内容仍在明面上（见 `DEPLOY.md` 第 18 章） |
| **来源入口** | 本系统 MIT 开源，**每个页面都带 GitHub 仓库入口且不可隐藏**：登录后侧栏顶部、全站页脚、登录页右上角。写死常量，不读配置，后台没有开关（见 `DEPLOY.md` 第 19.2 节） |

内容有两种形态，可以混用：

- **问答**：一条问题 + 一条答案，带来源和日期，适合从群聊/工单沉淀
- **文档**：一篇长文，可以把多条问答按顺序收进来，形成可通读的手册

---

## 2. 环境要求

- PHP **8.0+**（推荐 8.1/8.2），扩展：`pdo`、`mbstring`、`openssl`、`zip`
- 数据库：**SQLite**（默认，零配置）或 **MySQL 5.7+ / MariaDB 10.3+**
- Web 服务器：Nginx 或 Apache（宝塔、虚拟主机都行）
- 根目录可写（要生成 `config.php`），`storage/` 可写

---

## 3. 安装

1. 把 `kb-portal-1.1.0.zip` 上传到服务器，解压到站点根目录（或子目录）
2. 浏览器打开 `https://你的域名/install.php`
3. 按提示填：
   - 站点名称
   - 数据库：默认 SQLite（文件路径留默认即可）；要用 MySQL 就切过去填连接信息
   - 管理员账号 + 密码（≥8 位）
4. 点「开始安装」，看到「安装完成」即可
5. **删掉 `install.php`**

> SQLite 的数据文件在 `storage/kb.sqlite`，备份要**同时复制它和 `config.php`**：
> Key 的哈希和明文都跟 `config.php` 里的 `security.pepper` 绑定，只搬数据库会让所有 Key 全部失效。
> 装好后 `install.php` 会被 `storage/install.lock` 锁住，即使忘了删也不会被重装覆盖。

### 装完的第一件事：把人跟数据放进去

1. 后台 → 用户管理 → 建普通用户（比如 `wayfair`）
2. 后台 → 分组管理 → 建分组（比如 `跨境电商`）
3. 后台 → 分组授权 → 选中该用户 → 勾「可读」（需要人工编辑内容再勾「可写」）→ 保存
4. 后台 → 导入数据 → 用内置的 `data/wayfair-qa.csv` 或上传自己的 CSV → 选分组和用户 → 开始导入
5. 用该用户登录 → 我的 API Key → 新建，选分组、勾读/写 → **复制明文 Key**（只显示一次）

---

## 4. 权限模型（重点）

两层，且**只收紧、不放大**：

```
超级管理员
   └── 给「用户 → 分组」授权 can_read / can_write
          └── 用户建 API Key，只能在自己的权限内选，且 Key 还能再关掉读或写
                 └── Agent 实际能力 = 用户权限 ∩ Key 开关
```

举例：

- 用户只有「跨境电商」的读权限 → Key 只能绑这个分组，写选项直接置灰
- 用户本来有写权限，后来被管理员收回 → 已发出的 Key 立刻变成只读（不用吊销）
- Key 绑定了「跨境电商」→ 访问别的分组的条目会返回 403，即使那条内容存在
- **即使这个 Key 是管理员发的，也越不过分组边界** —— 超管要跨组操作请用后台网页，不要指望 Key
- 请求体里带 `group_id` 会被忽略，写到哪儿完全由 Key 决定
- 用户自建的分组就是他的私有空间，别人无法被授权进去（见下一节）

### 4.1 注册用户怎么隔离

开放注册后，新账号会自动得到一个「私有空间」：

```
users.id  ──owner_id──▶  groups（owner_id > 0）
                              └── group_user 只有他一条（读写）
```

- `groups.owner_id` 标记归属；`owner_id = 0` 是共享分组，`> 0` 是某人的私有空间
- 私有空间**不出现在「分组授权」页**，管理员也无法把人塞进去 —— 这是隔离的底线
- 用户之间：列表看不到、搜索搜不到、拿着 id 直接访问返回 403
- 站长可审计：后台「内容管理」能看所有空间的内容，用于排查和处置违规

一句话：**隔离是对外的，不是对站长的**。一个用户的 Key 泄露，连带不到别人的数据。

### 4.2 昵称与分组命名（1.4.0）

昵称现在是**全站唯一**的对外标识，规则如下：

- 注册、后台建号、账户页改昵称，三处都会校验：不能和别人的昵称重复，也不能和别人的登录名撞（不区分大小写）
- 昵称不能含「·」——这个符号留给「昵称 · 分组名」做分隔；全角空格、连续空白会自动收成一个
- 留空则退回用登录名

私有分组的名称统一带上归属人昵称，管理员在后台一眼看出是谁的：

| 场景 | 名称 |
|---|---|
| 注册时自动建的默认空间 | `昵称 的空间`（例：`小新 的空间`） |
| 用户自建 / 管理员给用户建的分组 | `昵称 · 自定义名`（例：`小新 · 售后话术`） |
| 共享分组（`owner_id = 0`） | 原样，不加前缀 |

用户改昵称后，名下**所有**分组名自动重写（默认空间跟着换前缀，自定义名保留），实测不会叠成「新昵称 · 旧昵称 · 原名」。

改名入口有两处：

- 前台分组页底部的「分组设置」——创建者本人和管理员可见，可改名称和说明；私有分组只填「·」后面那截，前缀由系统维护
- 后台「分组管理」——同样只改后半截，前缀自动补回

后台分组管理按归属拆成两栏：**我的分组**（`owner_id = 0` 的共享分组，以及管理员自己的空间）和**用户的分组**（其他人的私有空间）。前台左侧栏只列自己的分组——管理员也一样，别人的分组统一从「管理后台 → 分组 → 用户的分组」进（2.1.0 起）。

### 4.3 开放 / 关闭注册

后台 →「系统与升级」→「注册与访问」：

| 设置 | 说明 |
|---|---|
| 允许访客自己注册 | 关掉后登录页的「注册新账号」入口消失，直接访问注册地址返回「未开放」 |
| 同一 IP 每小时最多注册 | 默认 5，范围 1~100；同时全站每小时上限自动取它的 5 倍（最低 20），防止被批量刷库 |

注册端有三道门：

1. **算术验证码** —— 每次渲染随机出题，答错或答对都立即作废，同一题不能复用
2. **按 IP 限速** —— 每小时尝试次数上限（成功数 × 3，最低 10），失败也计数
3. **基础校验** —— 账号 3~32 位字母数字，密码 ≥ 8 位且不能全数字、不能等于账号

注册成功会立刻登录，并创建名为「<昵称> 的空间」的私有空间，直接跳首页。
首页在空间为空时会显示引导：建 API Key 让 Agent 推数据，或手动新建。

**升级上来的老数据**：迁移会按保守规则回填归属 —— 只有「该分组恰好一个成员、这个人是普通用户、且有写权限」才认定为他的私有空间；管理员参与的、多人协作的一律保持共享，避免误伤。

**删除用户**时，他的私有空间不会跟着消失，而是 `owner_id` 归 0 转为共享分组（内容保留，只有管理员能看），由你决定清空还是重新分配。

### 4.4 邮件：验证码 / 找回密码 / 通知

后台 →「邮件设置」，填完保存、发一封测试信通了再启用。

| 配置 | 说明 |
|---|---|
| 发送方式 | **SMTP**（推荐）或 **PHP mail()**（主机自带 MTA 时才可用） |
| 服务器 / 端口 / 加密 | SSL（465）、STARTTLS（587）、不加密（25 内网中继）三种 |
| SMTP 账号 / 密码 | QQ、163 这类要填「授权码」，不是登录密码；密码用 AES-256-GCM 加密后入库 |
| 发件人 | 多数服务商要求与 SMTP 账号一致，否则会被拒收 |
| 注册必须验证邮箱 | 打开后注册要填邮箱 + 验证码，账号天生带一个已验证邮箱 |
| 注册成功发欢迎邮件 | 可选，用来确认整条链路是通的 |

启用后各处的能力：

| 场景 | 行为 |
|---|---|
| 注册 | 关掉「必须验证」时退回算术验证码，邮箱选填（未验证，收不到通知） |
| 忘记密码 | 登录页出现「忘记密码」：填邮箱 → 收 6 位码 → 设新密码。邮箱没注册过也返回同样的提示，不会泄露谁有账号 |
| 账户设置 | 改昵称、改密码（凭旧密码）；绑定/更换邮箱（验证码发到新地址）；用邮箱验证码改密码（忘了旧密码时用）；开关通知邮件 |
| 后台通知 | 选主题 + 正文群发，只发给「邮箱已验证且没关通知」的人，单次上限 200 人 |
| 后台用户管理 | 可代设邮箱，代设视为已验证（老账号补邮箱用这个） |

验证码规则：6 位数字，**15 分钟**有效，一次性使用，错 5 次作废；同一邮箱同用途 **60 秒**内不能重发，
每小时最多 6 封，同一 IP 每小时最多 15 封。验证码只存 HMAC，不存明文。

发送记录在「邮件设置」页底部（最近 30 条，含失败原因），排查时先看那里：

- `535` 认证失败 → 多半填了登录密码而不是授权码
- `连接超时` → 主机商封了外发 SMTP 端口，换 587 或改用企业邮箱
- `550 / 554` → 收件地址被拒或被判垃圾邮件

> 备份提醒：SMTP 密码与 API Key 明文都和 `config.php` 的 `security.pepper` 绑定，搬库必须连 `config.php` 一起搬。

---

## 5. Agent 接口（核心）

整套系统对外就这一层：`api.php?path=...`，鉴权用请求头：

```
Authorization: Bearer kb_xxxxxxxxxxxxxxxx
```

### 5.0 接口地址填什么

登录后进 **「我的 API Key」**，页面顶部有一张「接口地址」卡片，两种形式分开列、各自带复制按钮：

| 形式 | 长什么样 | 用在哪 |
|---|---|---|
| **Base URL** | `https://kb.example.com` | Python / JS 客户端、SDK（`agent_client.py` 的 `KB(url, key)`） |
| **完整入口** | `https://kb.example.com/api.php?path=` | curl、Postman、curl 类 HTTP 工具 |

客户端内部会自己拼 `/api.php?path=/v1/…`，**所以 Base URL 里不能带 `/api.php`**，否则会拼成 `/api.php/api.php`。
页面上的地址按当前访问域名自动生成；如果你是用内网 IP 打开后台，复制到的也会是内网地址，
要让外部 Agent 用，请在 `config.php` 里固定域名：

```php
'app' => [
    'url' => 'https://kb.example.com',   // 留空则自动取当前访问域名
],
```

卡片右下角有个「测试连通」按钮，点一下会请求 `/v1/health`，直接告诉你 PHP 和接口是否正常。

配了伪静态也可以直接 `/api/v1/search?q=...`（见第 9 节）。

### 5.1 接口一览

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/v1/health` | 健康检查（不需要 Key） |
| GET | `/v1/me` | 当前 Key 的身份、分组、读写能力、条目数。**Agent 启动时先调一次** |
| GET | `/v1/groups` | 可访问分组（一个 Key 只会返回它绑定的那一个） |
| GET / POST | `/v1/search?q=&limit=&offset=&kind=` | 本分组内检索，返回 `score` 和 `snippet`。整段买家咨询用 POST + JSON body，避免 URL 过长 |
| GET | `/v1/entries?limit=&offset=&kind=` | 按更新倒序列出条目 |
| GET | `/v1/entries/{id}` | 读一条（文档会带 `links`） |
| GET | `/v1/entries/{id}/versions` | 版本历史，最后一条是当前版本（`is_current=true`） |
| POST | `/v1/entries` | **幂等写入**：单条或 `{"items":[...]}` 批量（≤200） |
| PUT | `/v1/entries/{id}` | 精确修改（已知 id 时用），版本号 +1 并留快照 |
| DELETE | `/v1/entries/{id}` | 软删除（前台立刻不可见，`changes` 里能查到删除事件） |
| GET | `/v1/changes?since=&limit=` | 增量拉取：`since` 之后被改动的条目，含删除标记，带 `next_since` |
| GET | `/v1/export?after=&limit=&kind=` | 全量分页导出，给 Agent 在本地建缓存/索引 |
| GET | `/v1/docs/{id}` | 文档正文 + 收录的问答 |

### 5.2 写入语义（最该看懂的一段）

`POST /v1/entries` 默认就是 `mode=upsert`，服务端会按**问题指纹**判重：

问题指纹 = 去标点、去空白、转小写后的前 300 字做 sha1。所以下面这几个算同一个问题：

```
DS 仓库没货了怎么办？
ds仓库没货了怎么办
DS仓库没货了怎么办
```

写入结果只有三种：

| action | 触发条件 | 服务端做了什么 |
|---|---|---|
| `created` | 库里没有这个问题 | 新增，`version=1` |
| `skipped` | 有这个问题，**答案指纹相同** | 什么都不改（不动 `updated_at`），只在原值缺失时补字段 |
| `updated` | 有这个问题，**答案变了** | 先把旧版本存进 `entry_versions`，再覆盖，`version + 1` |

也就是说：**重复推是安全的**，每天把全量问答总表推一遍也不会堆出副本，只有真正改过答案的条目才会产生新版本。

返回长这样，`results` 里逐条告诉你每条是哪种结果：

```json
{"ok":true,"data":{"ids":[1,2],"created":1,"updated":0,"skipped":1,
  "results":[{"index":0,"action":"created","id":1,"version":1,"changed":[]},
             {"index":1,"action":"skipped","id":2,"version":3,"changed":[]}]},
 "errors":[]}
```

确实想强制插入一条（比如同一句话在不同场景下要分开存），传 `"mode":"create"`。

### 5.3 curl 示例

```bash
KEY=kb_xxxxxxxx
BASE="https://kb.example.com/api.php?path="

# 我是谁
curl -H "Authorization: Bearer $KEY" "${BASE}/v1/me"

# 搜索，取前 5 条
curl -G -H "Authorization: Bearer $KEY" --data-urlencode "path=/v1/search" \
     --data-urlencode "q=库存清零" --data-urlencode "limit=5" \
     "https://kb.example.com/api.php"

# 幂等写入一条问答
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"question":"DS 仓库没货了怎么办？","body":"先停售，再按能否补货分别处理。","source":"群聊","source_date":"2026-11-18"}' \
  "${BASE}/v1/entries"

# 批量写入
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"items":[{"question":"Q1","body":"A1"},{"question":"Q2","body":"A2"}]}' \
  "${BASE}/v1/entries"

# 看某条的版本历史
curl -H "Authorization: Bearer $KEY" "${BASE}/v1/entries/17/versions"
```

> Windows 的 Git Bash 会把 `/v1/search` 当路径转换成 `C:/Program Files/...`。
> 用 `--data-urlencode "path=/v1/search"`（如上）或设 `MSYS_NO_PATHCONV=1` 就好。

返回统一是 `{"ok":true,"data":...,"meta":{...}}`；出错是 `{"ok":false,"error":{"code":"...","message":"..."}}`。

错误码：`unauthorized`(401 Key 无效/过期/吊销)、`readonly`(403 只读 Key 写入)、`forbidden`(403 分组不匹配或权限被收回)、`not_found`(404)、`bad_request`(400 参数问题)。

### 5.4 本地 Agent 怎么接

`examples/` 下有两个能直接用的脚本（零依赖，只用标准库）：

```bash
# 1) 客户端封装：查、写、看版本
from agent_client import KB
kb = KB("https://kb.example.com", "kb_xxxxxxxx")
kb.me()
kb.search("库存清零", limit=5)
kb.push([{"question": "…", "body": "…", "source": "群聊"}])

# 2) 把「问答总表」CSV 推进去（每天增量同步就是这一步）
python examples/push_qa_csv.py --url https://kb.example.com --key kb_xxx \
    --csv /path/to/问答总表.csv --source 群聊
```

`push_qa_csv.py` 会分批（默认 100 条/批）推完并打印「新增 / 更新 / 跳过」三个数。
第一次跑建议加 `--dry-run` 先看读到了多少条。

推荐的 Agent 工作流：

1. `GET /v1/me` 确认分组和写权限
2. 要回答用户问题：`/v1/search` 拿 `snippet` 判断相关性，需要完整答案再 `GET /v1/entries/{id}`
3. 要沉淀新内容：直接 `POST /v1/entries`，**不用先查重**——服务端已经幂等
4. 想复核「哪些答案变过」：`/v1/changes?since=上次时间` 拿变更列表，再用 `/versions` 看具体改了什么

### 5.5 版本记录怎么用

群聊里同一个问题，官方/主管过几天给了新答案，这是常态。系统不会覆盖得无迹可寻：

- 每次 `updated` 都把**覆盖前的值**（标题/问题/答案/来源/日期）存进 `entry_versions`
- 后台问答详情页底部有「历史版本」，点开就能看到每次修改前的原文
- Agent 侧用 `GET /v1/entries/{id}/versions` 拿结构化数据

---

### 5.6 接 ChatGPT / Claude（MCP，1.5.0）

除了 REST，系统还自带一个 **MCP 服务器**，支持 MCP 的客户端可以不用写任何代码直接读写。

两个地址（在「我的 API Key」页面能看到，可一键复制）：

| 用途 | 地址 |
|---|---|
| MCP 服务端 | `https://你的域名/mcp.php` |
| OpenAPI 描述 | `https://你的域名/openapi.php` |

**ChatGPT（连接器 / 插件）**：添加自定义连接器 → 填 MCP 地址 → 鉴权选 Bearer，粘一个 `kb_` 开头的 Key。
如果你的套餐或客户端版本只有「OAuth / 无鉴权」两个选项（部分版本还没放开 Token 方式），改走下面这条。

**自定义 GPT（Actions）**：Action → Import from URL → 填 OpenAPI 地址 → 认证选 API Key → Auth Type 选 **Bearer** → 粘 Key。
注意 OpenAI 已宣布自定义 GPT 逐步退役（标准退役日 2026-12-11），这条只适合过渡。

**Claude / Cursor / 其他 MCP 客户端**：

```json
{"mcpServers":{"kb":{"url":"https://你的域名/mcp.php",
  "headers":{"Authorization":"Bearer kb_你的Key"}}}}
```

暴露的五个工具：

| 工具 | 权限 | 作用 |
|---|---|---|
| `kb_search` | 读 | 中文全文检索，返回带 `[id:x]` 的条目 |
| `kb_get` | 读 | 按 id 取全文（搜索结果被截断时用） |
| `kb_changes` | 读 | 按时间增量拉改动，含删除标记 |
| `kb_info` | 读 | 当前 Key 绑的是哪个库、多少条、能不能写 |
| `kb_upsert_qa` | **写** | 写入问答，按问题指纹幂等 |

几个要点：

- **权限口径和 REST 完全一样**：一个 Key 只认一个分组，`can_write` 没勾的 Key 调 `kb_upsert_qa` 会返回「只读」错误，模型会照实转述。
- **无状态、不发 SSE**：每个 POST 一条 JSON-RPC，直接回 JSON。不用管会话过期，也方便放在任何反向代理后面。
- **能一键关掉**：后台「系统与升级」里取消勾选「开放 MCP 接入」，`/mcp.php` 立刻返回 403，已有连接失效，Key 和内容都不受影响。
- **写给第三方之前想清楚**：接上 ChatGPT 等于把这个分组的内容随对话发给 OpenAI。敏感库建议单独建一个只读 Key + 单独分组。

### 5.7 对外开发文档（1.6.0）

要给别人接入时，不用再手写说明，直接把链接发过去：

| 用途 | 地址 | 是否要登录 |
|---|---|---|
| 开发文档（人看） | `https://你的域名/index.php?r=docs` | 不需要 |
| 纯文本索引（Agent 看） | `https://你的域名/llms.txt` | 不需要 |
| OpenAPI 描述 | `https://你的域名/openapi.php` | 不需要 |
| 健康检查 | `https://你的域名/api.php?path=/v1/health` | 不需要 |

文档页覆盖：鉴权与权限、响应格式与错误码、13 个 REST 接口的参数表、写入幂等语义、增量同步、MCP（工具表 + 客户端配置 + 状态码）、OpenAPI 导入、Python/Node 示例、限额表、排查手册。

- 页面里所有地址都由 `base_url()` 现算，换域名不用改内容；`config.php` 固定了 `app.url` 就按固定值生成。
- 登录/未登录都能看，只是右上角按钮不同；「我的 API Key」页面底部也有入口。
- `.htaccess` 里加了 `^llms\.txt$ → llms.php` 的 rewrite；nginx 想用 `/llms.txt` 就加 `location = /llms.txt { rewrite ^ /llms.php last; }`，不配也能直接访问 `/llms.php`。
- 新增文件：`app/views/docs.php`、`assets/docs.css`、`llms.php`；这两个入口已加进更新包与全量包的 `$include`。

---

## 6. 文件存储（1.7.0 起）

内容和图片可以分开存：文字在库里，文件在存储平台上。

- **平台**：本地磁盘（默认，零配置）/ 阿里云 OSS / 腾讯云 COS / 七牛 / 又拍云 / Cloudflare R2（含自建 MinIO）。
  后台「文件存储」里填参数，保存后先「测试连接」，通过再启用。
- **多副本**：上传时同时写到「默认平台 + 所有已启用平台」。某个平台挂了不影响上传，只在附件列表里标一个失败。
- **灾备补齐**：中途新接一个平台时它是空的，点该行「同步数据」，后台会把每个缺失的文件从其他平台拉过来写一份。
  只补不删、可中断续跑，再点一次接着补。
- **容灾读取**：下载按副本顺序依次尝试，某个平台取不到会自动换下一个，并把坏掉的副本标成失败等下次同步修。
- **对外只有一条路**：`index.php?r=f&id=12`（登录态）或 `/v1/attachments/{id}/download`（Key）。
  不暴露对象存储地址、不生成公开直链，权限始终跟着分组走。
- **正文插图**：`![](/index.php?r=f&id=12)`；后台附件列表有「复制图片引用」按钮。
- **安全**：扩展名白名单（刻意不含 svg/html/php/js），图片会真的解析一遍防改名伪装，
  同组同内容自动去重，单文件上限默认 20 MB（`settings` 的 `file_max_mb`）。

---

## 在线支付（1.9.0 起）

积分/套餐可以直接线上收款，渠道是**彩虹易支付**（各类「易支付」同协议站点都适用，V1 接口、MD5 签名）。

- **支付模块独立**：`app/pay/`（`gateway.php` 抽象 + `epay.php` 驱动 + `orders.php` 业务 + `web.php` 控制器）。
  以后接微信/支付宝官方，实现同一个 `PayGateway` 接口即可，订单与发货逻辑不动。
- **怎么开**：后台「支付设置」→ 填接口地址、商户 ID、商户密钥 → 测试连接 → 勾支付方式 → 打开开关。
  三项没配齐时开关会自动按回关闭，避免前台报错。
- **链路**：`去支付` → 建单（pending）→ **本页弹窗里付款** → **异步回调验签 + 商户号 + 金额一致 + TRADE_SUCCESS** → 发货。
  同步跳转页只展示结果，不凭它发货。
- **弹窗支付（1.9.1）**：平台给 `qrcode` 就自己画二维码（手机扫码，`assets/qr.js` 零依赖，不外发订单链接）；
  只给收银台地址就内嵌 iframe，并保留「在新窗口完成支付」兜底。弹窗每 2.5 秒查一次状态
  （`?r=pay_status`，顺带向平台查单，6 秒节流），付完自动显示「支付成功 → 请关闭弹窗」。
- **兑换码购买入口**：后台可填「购买页名称 + 地址」，用户充值页兑换区就会出现「去 xxx 购买」按钮。
- **幂等**：平台重复推送只记日志、不再发货；金额被篡改、签名不对、商户号不对一律回 `fail` 且订单保持待支付。
- **补单**：通知丢了（防火墙/CDN/域名变更常见），用户点订单「刷新状态」或后台点「查单 / 补发货」，
  会主动向平台查一次，确认已付就地发货。
- **退款**：先到商户后台打开「订单退款 API」。退款成功后订单置 `refunded`；积分包余额够就自动扣回，
  不够只留痕；月付单把该单产生的权益置为失效。
- **留痕**：每次发起/回调/查单/退款都写 `pay_events`（含验签结果与来源 IP），后台可查，排查「付了钱没到账」全靠它。

发货只走 `ai_order_pay()` 一个入口，手动标记、兑换码、在线支付回调共用，不会出现两套账。

---

## 7. 中文搜索是怎么做的

没有用 MySQL 全文索引（它对中文分词支持差、要改表引擎），也没接 Elasticsearch。

做法：入库时把标题+问题+正文切成**二字组**（「库存清零」→ 库存/存清/清零）写进 `entry_grams` 索引表；
查询时同样切词，按命中数量排序，再对「问题命中」「标题命中」加权，最后按命中覆盖率过滤掉只沾一两个词的噪声。

实测 1800 条问答：搜「库存清零」召回 23 条，第一条就是讲这个的；索引约 47 万个二字组，查询在几十毫秒量级。

优点：SQLite / MySQL 行为一致，不依赖任何外部服务，中文片段（两个字）也能搜到。
代价：数据量到十万级时 `entry_grams` 会很大，到时候建议加 `gram` 的分区或换 Meilisearch。

---

## 8. 升级

### 7.1 在开发机打更新包

```bash
php tools/build_update.php --version=1.1.0 --notes="修了搜索排序；新增导出功能"
```

产物在 `dist/update-1.1.0.zip`，结构：

```
update-1.1.0.zip
├── version.json        {"version":"1.1.0","min_version":"1.0.0","notes":"..."}
├── files/              app/ assets/ index.php api.php ... （不含 config.php、storage、data）
└── migrations/         1.1.0.php（可选，return function(PDO $pdo){ ... }）
```

### 7.1b 同步到 GitHub（2.2.0 起，每次发版必做）

系统开源在 `https://github.com/arch-chen/xc-kb-portal`。**以后每次发版都要把更新包与清单推上去**，
否则后台「GitHub 在线更新」读到的还是旧清单。后台默认走 `api.github.com`（国内可达；
`raw.githubusercontent.com` 常被墙），通道可在「更新源设置」里切换：

```bash
php tools/publish_github.php            # dist/update-*.zip → updates/，重算 updates.json（含 sha256），commit + push
php tools/publish_github.php --dry-run  # 只预览，不提交
```

清单 `updates.json` 记录每个包的版本、`min_version`、说明与 sha256；后台按它自动连升多个版本。
完整流程与安全边界见 `DEPLOY.md` 第 16 章。凭据走系统 credential helper，不要写进任何文件。

### 7.2 在服务器上升级

**推荐**：后台 → 系统与升级 → 顶部「GitHub 在线更新」→ 检查更新 → 立即更新，
系统自动按链下载并校验，不用手工传包。

手动方式：后台 → 系统与升级 → 选择 zip → 开始升级。系统会：

1. 校验 `version.json`：版本必须高于当前，且满足 `min_version`
2. **备份数据库**到 `storage/backups/`（SQLite 直接复制，保留最近 5 份）
3. 解压并覆盖 `files/` 下的文件；`config.php`、`storage/`、`data/` 一律跳过
4. 按文件名顺序执行 `migrations/*.php`，执行过的记在 `settings.mig_*` 里，不会重复跑
5. 更新 `version.json`，写升级日志

失败回滚：把 `storage/backups/db-*.sqlite` 复制回 `storage/kb.sqlite`，再把旧版源码覆盖回去。

### 7.3 写迁移

```php
<?php // migrations/1.1.0.php
return function (PDO $pdo): void {
    $p = db_prefix();
    $pdo->exec("ALTER TABLE {$p}entries ADD COLUMN tags VARCHAR(255) NULL");
};
```

**迁移文件必须自包含。** 升级请求跑起来的时候，进程里加载的还是旧版 `app/` 里的代码；文件虽然已经覆盖到磁盘上了，但函数不会重新加载。
所以迁移里不能调用新版本才有的函数（`entry_qfp()` 之类），要用的逻辑就地写一份。

### 7.4 1.0.0 → 1.1.0 会做什么

后台传 `dist/update-1.1.0.zip` 即可，自动完成：

1. 备份数据库到 `storage/backups/db-*-v1.0.0.sqlite`
2. 覆盖 44 个文件（`config.php`、`storage/`、`data/` 跳过）
3. 执行 `migrations/1.1.0.php`：给 `entries` 加 `qfp`/`afp` 两列、建 `entry_versions` 表、**回填历史条目的指纹**
4. 版本号写回 1.1.0

回填实测：1814 条问答 0.1 秒，升级完成后已有数据立刻具备「重复推不新增」的能力，不用重新导入。

### 7.5 1.1.3 → 1.2.0 会做什么

后台传 `dist/update-1.2.0.zip`，自动完成：

1. 备份数据库
2. 覆盖 47 个文件
3. 执行 `migrations/1.2.0.php`：
   - `groups` 加 `owner_id`（默认 0）
   - 建 `throttle` 表（注册限速）
   - 写入 `allow_register=1`、`register_per_hour=5`（已有值不覆盖）
   - 按保守规则回填历史私有空间归属
4. 版本号写回 1.2.0

升级后默认**已经开放注册**。不想开放就进后台关掉，入口会立即消失。

### 7.6 1.2.0 → 1.3.0 会做什么

后台传 `dist/update-1.3.0.zip`（从 1.1.x 直接上来也可以，包里带了 1.2.0 的迁移），自动完成：

1. 备份数据库
2. 覆盖程序文件
3. 执行 `migrations/1.3.0.php`：
   - `users` 加 `email` / `email_verified_at` / `notify_mail`（默认 1）
   - 建 `email_codes`（验证码）与 `mail_log`（发送记录）两张表
   - 写入 `mail_*` 默认值（已有值不覆盖），邮件开关默认**关闭**
   - 老账号如果用户名本身就是邮箱，回填到 `email`，但保持「未验证」
4. 版本号写回 1.3.0

升级完邮件是关着的，原有注册/登录行为不变。要用验证码、找回密码、通知，去「邮件设置」配好 SMTP 并发一封测试信，通了再勾「启用邮件功能」。

### 7.7 1.3.0 → 1.4.0 会做什么

后台传 `dist/update-1.4.0.zip`（`--from=1.1.0` 打的，1.1.x 起的版本都能直接升；包里带了 1.1.2 / 1.2.0 / 1.3.0 / 1.4.0 的迁移），自动完成：

1. 备份数据库
2. 覆盖程序文件（新增 `app/identity.php`）
3. 执行 `migrations/1.4.0.php`：
   - 昵称归一化：空的补成登录名，重复的自动加「（2）」，含「·」的换成「-」
   - 给 `users.display_name` 建唯一索引（SQLite 用 `COLLATE NOCASE`）
   - 私有分组改名：名下第一个（默认空间）改成「昵称 的空间」，其余改成「昵称 · 原名」（已经带前缀的不重复叠加）
4. 版本号写回 1.4.0

迁移只动昵称和分组名，内容和权限关系不变。如果历史昵称撞了别人的登录名，会被改名加后缀——升级完在后台「用户管理」看一眼昵称列即可。

### 7.8 1.4.0 → 1.5.0 会做什么

后台传 `dist/update-1.5.0.zip`（`--from=1.1.0`，1.1.x 起都能直接升）：

1. 备份数据库
2. 新增 `mcp.php`、`openapi.php`、`app/mcp.php`，`app/views/keys.php` 与后台系统页更新
3. 执行 `migrations/1.5.0.php`：只插入一个设置项 `mcp_enabled=1`，**不改表结构、不碰数据**
4. 版本号写回 1.5.0

升级完 MCP 就是开着的（但没有 Key 也调不通，Key 仍然要你在「我的 API Key」里建）。
不想开放就去「系统与升级」把勾去掉。

---

## 9. 目录结构

```
kb-portal/
├── index.php            所有页面入口（?r=路由）
├── api.php              Agent API 入口（?path=/v1/...）
├── mcp.php              MCP 入口（ChatGPT / Claude 连接器）★
├── openapi.php          OpenAPI 描述（自定义 GPT 的 Actions 导入）★
├── install.php          安装向导（装完请删）
├── version.json         当前版本
├── config.php           安装时生成（含数据库密码与 pepper，禁止外泄）
├── app/
│   ├── bootstrap.php    引导：配置、会话、URL/视图助手
│   ├── db.php           PDO 封装 + 建表
│   ├── auth.php         登录、限速、key_hash
│   ├── register.php     注册开关、验证码、IP 限速、私有空间创建
│   ├── identity.php     昵称唯一校验、分组名昵称前缀与同步 ★
│   ├── perm.php         双层权限判断
│   ├── search.php       二字组检索
│   ├── text.php         归一化、分字、Markdown、摘要高亮
│   ├── entries.php      条目/文档读写
│   ├── upsert.php       幂等写入（问题指纹、答案指纹、版本快照）★
│   ├── secret.php       API Key 明文的加密存储与脱敏 ★
│   ├── smtp.php         零依赖 SMTP 客户端（SSL / STARTTLS / AUTH）★
│   ├── mail.php         邮件配置、模板、验证码、通知、发送日志 ★
│   ├── importer.php     CSV 导入
│   ├── upgrade.php      在线升级
│   ├── web.php          页面控制器
│   ├── api.php          REST 接口（Agent 唯一入口）
│   ├── mcp.php          MCP 服务端：JSON-RPC 2.0 / 工具定义 / 鉴权 ★
│   └── views/           模板
├── assets/              app.css / app.js（含亮暗主题）
├── data/                内置导入数据（wayfair-qa.csv）
├── examples/            Agent 客户端示例（Python，零依赖）
│   ├── agent_client.py    KB 类：search / push / versions / changes / export
│   └── push_qa_csv.py     把问答总表 CSV 幂等推进去
├── storage/             SQLite 数据库、备份、临时文件（禁止外部访问）
├── tools/
│   ├── import_wayfair.php   命令行导入
│   └── build_update.php     打更新包
└── migrations/          升级迁移脚本
```

---

## 10. 服务器配置

### Nginx（推荐）

```nginx
server {
    listen 80;
    server_name kb.example.com;
    root /www/wwwroot/kb-portal;
    index index.php;

    # 保护配置、数据与代码目录（**这行不能省**：SQLite 库、备份、种子 CSV 都在里面）
    location ~ ^/(config\.php|app/|data/|storage/|migrations/|tools/) { deny all; return 404; }
    location ~* \.(sqlite|sqlite3|db|db-wal|db-shm|csv|log|lock|bak|zip|sql)$ { deny all; return 404; }

    # 给 Agent 读的纯文本索引（可选，不配就用 /llms.php）
    location = /llms.txt {
        rewrite ^ /llms.php last;
    }

    # 伪静态：/api/v1/xxx 与 /g/xxx
    location /api/ {
        rewrite ^/api/(.*)$ /api.php?path=/$1 last;
    }
    # 可选：让 MCP 地址变成 /mcp（不配也能用 /mcp.php）
    location = /mcp {
        rewrite ^ /mcp.php last;
    }
    location / {
        try_files $uri $uri/ /index.php?p=$uri&$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/tmp/php-cgi-81.sock;
        fastcgi_index index.php;
        include fastcgi.conf;
    }
}
```

### Apache

包内已带 `.htaccess`，确保 `AllowOverride All`、开启 `mod_rewrite` 即可。

### 安全清单

- [ ] **验证数据目录不可访问**：浏览器打开 `https://域名/storage/kb.sqlite` 和 `/data/wayfair-qa.csv`，必须 404/403。
  nginx **不读** `.htaccess`，上面那段必须真的加到站点配置里；这是整站最容易被漏掉、后果最重的一处
- [ ] 删掉 `install.php`
- [ ] `config.php` 与 `storage/` 不能被浏览器访问（上面规则已覆盖；更彻底的做法是把库挪到 web 根目录之外）
- [ ] 全站 HTTPS（登录 cookie 会自动加 Secure）
- [ ] 后台账号用强密码；定期看「后台 → 最近操作」里的异常登录
- [ ] 定期备份 `storage/kb.sqlite`（或 MySQL 库）**以及 `config.php`**；
  备份文件别放在能被直接下载的目录，`storage/backups/` 也要靠上面的规则挡住

---

## 11. 常见问题

**Q：API Key 忘了怎么办？**
1.1.2 起不用重来。「我的 API Key」页面默认脱敏显示，点「显示」看完整内容，点「复制」直接进剪贴板。
明文是 AES-256-GCM 加密后存库的（密钥在 `config.php`），光拿到数据库文件看不出 Key。
1.1.2 之前创建的 Key 当初只存了哈希、还原不出来，页面会标「旧 Key，重建后才能查看」，吊销后重建一个即可，不影响其他 Key。

**Q：管理员能看到别人的 Key 吗？**
不能，这是故意的。能看明文就等于能冒充对方的 Agent，所以接口只对创建者本人放行，管理员在 Key 总览页只能吊销或恢复。

**Q：Agent 调接口报 404，接口地址到底填什么？**
登录后进「我的 API Key」，页面顶部就是「接口地址」卡片：**Base URL** 填 `https://你的域名`（客户端用），**完整入口**是 `https://你的域名/api.php?path=`（curl 用），两者都有复制按钮。
报 404 常见三种原因：① Base URL 里多带了 `/api.php`，被拼成 `/api.php/api.php`；② 站点装在子目录（如 `/kb/`）却漏了目录名；③ 没配伪静态却用了 `/api/v1/search` 这种短地址。
卡片上的「测试连通」会请求一次 `/v1/health`，能通就说明地址对、PHP 也正常。

**Q：注册用户能自己再建空间吗？**
能。用户点「新建分组」建出来的同样是私有空间（`owner_id` = 他自己），别人看不到，管理员也无法把人加进去。

**Q：注册用户能自己删号或改密码吗？**
目前都不能：删号、重置密码要在后台由管理员操作。改密码还没做，属于已知的空缺。

**Q：注册用户会不会把我的库看光？**
不会。注册只给他一个空空间，`group_user` 里没有共享分组的授权，搜索范围就是空集合。
除非你在「分组授权」里明确给他开某个共享分组的读权限。

**Q：导入会重复吗？**
不会。按「问题指纹」去重（去标点/空白/大小写后比对），API 和 CSV 导入都是这个规则。答案变了会覆盖并留旧版本。

**Q：Agent 每天推全量会不会越来越慢？**
不会，也不会变多。全量推 1813 条实测 0.7 秒，返回值里绝大多数是 `skipped`，数据库里没有新增行。想更省，就用 `GET /v1/changes?since=上次时间` 先问「哪些变过」，只推变过的。

**Q：怎么确认 Agent 写进来的东西没搞坏原答案？**
后台问答详情页拉到底有「历史版本」，每次被覆盖前的原文都在；也可以 `GET /v1/entries/{id}/versions` 拿 JSON 自己比。

**Q：一个 Key 能访问多个分组吗？**
不能，这是故意的。一个 Key 一个分组，权限边界清晰，泄露一个不影响别的。需要跨组就让 Agent 用多个 Key；人要看全部分组就登录网页后台。

**Q：文档和问答的关系？**
文档是独立条目，通过 `doc_links` 引用问答。改一条问答，所有收录它的文档页面立刻同步——不用手动复制粘贴。

**Q：为什么我的 CSV 导入后「分册」没了？**
导入时会按「分册」列自动生成对应的手册文档；分册值不符合 `01_xxx` 这种格式的会归到「其他」。

**Q：数据量大怎么办？**
5 万条以内 SQLite 完全够用；再大建议换 MySQL，并把搜索换成 Meilisearch（`app/search.php` 里只有一个入口函数，替换成本低）。

**Q：能不能多人协作编辑？**
可以，给用户开该分组的写权限即可，前台分组页会出现「新建问答/新建文档」。并发编辑没有锁，适合小团队。

---

## 12. 本地开发/预览

```bash
# 任意目录下的 PHP 8.1
php -S 127.0.0.1:8099 -t .
# 打开 http://127.0.0.1:8099/install.php
```

跑测试数据（命令行，需已有用户）：

```bash
php tools/import_wayfair.php --csv=data/wayfair-qa.csv --group=跨境电商 --user=wayfair
```
