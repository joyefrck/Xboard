# 大象网络每日七版异地备份运行与恢复手册

部署：2026-11-07 至 2026-11-08，北京时间。用户批准使用现有硅谷文件服务器，并最终指定每天一次、保留七个日版本、取消周/月版本；告警接入现有运维 Telegram。

## 范围和时间

| 项目 | 已部署行为 |
| --- | --- |
| 香港主站 | 阿里云 `47.238.145.117`，每天北京时间 03:10 全量备份 |
| 硅谷目的端 | 搬瓦工 `67.216.201.209`，`/srv/elephant-backups/daily/YYYY-MM-DD.cms` |
| 保留 | 最近 7 个成功日版本；失败不淘汰旧的成功版本；没有周/月归档 |
| 下载站自身 | 硅谷每天 02:40 生成加密副本，香港 03:10 拉取，香港保留 7 份，并将最新一份装入主站备份 |
| 轮转 | 硅谷每天 04:10；校验最新七份 SHA-256 后才清理更老版本 |
| 监控 | 硅谷每小时仅检查状态；不会每小时创建备份 |
| 预期数据窗口 | 约 24 小时加备份耗时；没有 binlog/PITR；不能恢复到任意分钟 |

主站归档包括已发现的业务数据库一致性 SQL（当前为 Xboard）、数据库账号导出、Redis RDB、应用代码和附件、`.env`/APP_KEY、`/etc`、所选 1Panel/OpenResty/MariaDB 配置、1Panel SQLite 在线副本、容器 inspect 和当前实际使用的镜像。数据库原始运行目录、访问日志及应用缓存排除。SQL 使用 `--single-transaction`；文件、Redis 与 SQL 不构成跨系统同一瞬间的事务快照，业务写入时仍可能存在短暂时间差。

下载站归档包含 Gokapi 文件、SQLite 在线副本、配置/模板、当前镜像、运行配置和已存在的 Caddy/防火墙配置。香港保存的这份密文是其异地副本。

**未覆盖：** 未登录盘点的独立支付主机、其他节点主机、本机以外的客户端签名密钥、云账号/域名/DNS 控制台数据。不能据此宣称所有外部系统均已备份。香港与硅谷均故障时，需要另存的离线密文及恢复材料。

## 存储与访问边界

目的端原系统盘约 157 GiB，部署前可用约 144 GiB。新建 `/srv/elephant-backup-100g.img`，100 GiB 稀疏文件，ext4 挂载到 `/srv/elephant-backups`，文件系统约 98 GiB。挂载项写入 `/etc/fstab`，带 `loop,nodev,nosuid,noexec,nofail`。它与根盘共用底层磁盘，是容量上限隔离，并非另一块物理盘。

挂载不存在或错误落到根文件系统时，接收端拒绝工作。目标根盘须至少剩余 20 GiB；备份分区达到 80 GiB 告警。主站每份当前约 3.84 GiB，七份约 26.9 GiB，实际以增长后的数据量为准。传输限速 8 MiB/s，任务互斥，CPU/I/O 优先级降低。

专用非 sudo 用户 `elephant-backup`：

- 香港上传密钥限定源 IP 和固定接收命令，只能提交当天一个新版本、读取状态及下载站密文；无 shell/SFTP/端口转发/覆盖/删除命令。
- Mac 维护密钥单独只读，只允许状态和密文下载。
- SSH 主机公钥固定，严格验证；不使用 `StrictHostKeyChecking=no`。
- 接收过程核对声明大小、EOF 和 SHA-256，临时文件 fsync 后原子提交，收据提交后才算成功。
- root 仍可删除文件，本方案不是 WORM。接收端只有主站备份密文，没有解密私钥。

初始系统配置备份位于硅谷 `/root/elephant-backup-setup-20261107`。香港最近一份本地密文位于 `/var/lib/elephant-backup/YYYY-MM-DD.cms`，不是额外的长期归档。上传确认后清理临时 SQL/镜像导出；香港下载站副本位于 `/var/lib/elephant-backup/download-site/`。

## 首次真实验收

| 验收 | 实测结果 |
| --- | --- |
| 主站 2026-11-07 密文 | 4,127,085,519 字节；上传提交、从硅谷取回和 SHA-256 均通过 |
| 主站解密 | 本机私钥解密，GCM 完整性验证通过；773 项 manifest 文件 SHA-256 全部匹配 |
| SQL 隔离恢复 | 实际归档 SQL 在无网络、无端口的临时 MariaDB 11.8.5 中导入；46 表检查通过 |
| APP_KEY | 使用原 APP_KEY 解密 47 项节点/搬瓦工加密凭据，通过；不输出明文 |
| 恢复关联 | 实际取回的 SQL SHA-256 与隔离导入的 SQL 完全一致 |
| Redis | 已归档 RDB 通过 `redis-check-rdb`；Redis PONG |
| 下载站 | 544,748,219 字节；从硅谷实际取回并解密，151 项文件校验通过，SQLite `integrity_check=ok`；同一密文已在香港落盘 |
| 故障保护 | 7 项接收/保留/挂载/越权测试，2 项告警去重/不确定回执测试，共 9 项通过 |
| 加密恢复工具 | 正常解密、密文篡改、截断测试通过；失败不发布恢复输出 |
| 业务运行 | 五个业务容器镜像/挂载/重启次数未变化；Web/Horizon init 和网络正确；僵尸 0 |
| 队列 | 三次间隔约 45 秒采样及数分钟后的新 SSH 复查，全部 12 队列有消费者，零新增失败；流量/统计/在线队列正常消费 |
| 公网 | 首页、`/app`、`/app#/login`、公开配置接口、CSS/logo、Gokapi 下载页均 HTTP 200；安装包入口返回正确文件类型并读取到文件字节 |
| Telegram | 复用既有订阅运维告警的 owner 用户及工单机器人；硅谷发送一条启用验证消息，API 返回成功，不等同设备已读 |

主站密文 SHA-256：`7943cab863af8a83c4ebf49e95ed4c7c9bcae1d538ae0c4ca70ecbbfe5a987ea`。

SQL SHA-256：`09ffc32ac2e14eb0a41c4617dcd17056014945d7391cfd8cd3e37cf09b3b4a85`。

下载站密文 SHA-256：`374dbf3e027aa5cbed756fbd0def92586b558a20c9c6c9ba2003c6bd16e8f72b`。

新连接复查时 Web/Horizon cgroup 任务数分别为 17/8831、29/8831；自然样本包含 34 条邮件结果、0 错误和 16 个 paid_at 订单计数。这不证明逐笔支付权益、收件箱、管理员/工单/群资格通知均已端到端验收。本次没有伪造注册、付款或真实工单；没有改动应用发布镜像或重启业务容器。

未进行整台替代 VPS 的 DNS 切换与业务接管演练；已完成数据库隔离恢复和归档文件校验，不能等同整站切换演练。

## 告警和日常查看

两端 `/etc/elephant-backup/telegram.json` 仅 root 可读，内容复用现有机器人和接收人，不在 Git、命令行参数或日志中输出。更换既有机器人 token 后，须同步更新这两份受保护配置。

主站备份失败及硅谷下载站/轮转任务失败，通过 `OnFailure=elephant-backup-notify@%n.service` 通知；下载站拉取失败也尝试通知，主站核心备份继续。硅谷独立检查主站超过 30 小时未提交、挂载/清单不可用、空间不足及本机备份任务失败。相同故障 6 小时去重；请求结果不确定时避免立即重发，检查恢复后发送恢复提醒。硅谷本身完全停机时，此监控无法自行发出提醒。

香港：

```sh
systemctl status elephant-backup.timer --no-pager
systemctl show elephant-backup.service -p Result -p ExecMainStatus -p OnFailure
tail -n 30 /var/log/elephant-backup.log
cat /var/lib/elephant-backup/last-success.json
```

硅谷：

```sh
systemctl list-timers --all --no-pager 'elephant*'
systemctl show elephant-download-site-backup.service elephant-backup-prune.service elephant-backup-monitor.service -p Id -p Result -p ExecMainStatus
journalctl -u elephant-backup-monitor.service --since today --no-pager
findmnt /srv/elephant-backups
df -h / /srv/elephant-backups
```

需要重试失败备份时，先查看失败原因，再手动 `systemctl start elephant-backup.service`。上传失败保留已生成的当天密文用于重试；不要清空 daily 目录。已有当天成功收据时不会覆盖。

## 恢复材料和步骤

本机恢复目录：`/Users/joyefrack/.local/share/elephant-disaster-recovery/20261107`（0700），含 RSA 恢复私钥、证书、只读维护 SSH 密钥、固定 known_hosts、恢复脚本、Bouncy Castle 1.86 依赖及校验文件。私钥未上传到两台服务器。**目前私钥只确认保存在这台 Mac，仍需要用户把整个恢复目录另存到离线移动硬盘/安全保险库，并验证可读。丢失私钥将无法解密这些备份。**

该目录中的 `primary-verification.json`、`download-site-verification.json` 和 `web-health.json` 记录实际验收，不含密码/token 明文。验证暂存目录可能含 SQL/SQLite 业务数据，禁止上传 Git 或通过聊天发送。

在可信机器、私有目录中恢复，需 Python 3.11+、OpenSSH 和 Java 17+。`verify.py` 会从目标取回最新成功版本、核对密文 SHA-256、解密并验证 manifest，不会启动应用：

```sh
umask 077
python3 /Users/joyefrack/.local/share/elephant-disaster-recovery/20261107/verify.py primary --directory /Users/joyefrack/.local/share/elephant-disaster-recovery/20261107
python3 /Users/joyefrack/.local/share/elephant-disaster-recovery/20261107/verify.py download-site --directory /Users/joyefrack/.local/share/elephant-disaster-recovery/20261107
```

换机器时改为新目录的绝对路径，保留目录结构及 `lib/*.jar`；用 `lib/sha256.json` 核对依赖。SSH 主机重建后必须重新通过可信渠道核对主机公钥，不能直接跳过校验。依赖取自 Maven Central 并对照其 SHA-256；[Bouncy Castle CMS 流式解析文档](https://downloads.bouncycastle.org/java/docs/bcpkix-jdk14-javadoc/org/bouncycastle/cms/CMSAuthEnvelopedDataParser.html)。OpenSSL CLI 的大文件读取曾触发限制，所以恢复统一使用已实测 3.84 GiB 备份的流式 Java 工具。

需要保留完整解密归档供重建时，在私有恢复目录中执行（依赖已随恢复材料保存）：

```sh
umask 077
java -Xmx256m --class-path '/absolute/recovery/lib/*' /absolute/recovery/CmsDecrypt.java /absolute/recovery/primary-2026-11-07.cms /absolute/recovery/recovery-private.pem /absolute/recovery/primary-2026-11-07.tar.gz
tar -tzf /absolute/recovery/primary-2026-11-07.tar.gz
```

先查看成员路径，再只解包到新建的空恢复目录，不能直接覆盖正在运行服务器的 `/etc` 或数据库目录。工具只在认证完整性通过后发布 `.tar.gz`；随后再根据 manifest 校验关键文件。

恢复顺序：

1. 在隔离的新服务器准备 Docker/网络和私有恢复目录，载入归档 `var/lib/elephant-backup/work/runtime-images.tar`。
2. 根据 `docker-inspect.json`、Compose 和配置文件恢复挂载/服务定义；保留原 `.env`/APP_KEY，核对数据库、Redis、邮件、支付和 Telegram 外部目标，暂不启用定时任务及队列。
3. 新建空 MariaDB，导入 `databases.sql`；单独审查 `database-users.sql` 后恢复数据库账号，不能直接拷贝旧运行数据库目录替代导入。核对 46 张表、数据和凭据解密；不同版本备份按其 manifest/实际表结构核对。
4. 恢复应用附件、配置与代码。Redis RDB 包含缓存/队列，审查待处理任务后决定恢复范围，避免重复处理历史通知/业务任务。
5. 启动 Web 后验证登录、套餐/订单、附件、订阅与节点配置；受控启动所有业务消费者，检查实际 Horizon provisioning、队列趋势和自然业务结果。
6. 主站接管稳定后才切换 DNS，并重新启用备份及告警。保留原始密文与恢复记录。

Gokapi 可以独立用香港 `/var/lib/elephant-backup/download-site/YYYY-MM-DD.cms` 恢复；主站归档也包含该密文。解密后从 `var/lib/elephant-download-site/work/gokapi/` 恢复文件和 SQLite，载入 `runtime-image.tar`，按 `runtime.json` 与 Compose 恢复挂载。DNS、Caddy 和管理域限制经核对后再开放。

`restore_check.py` 是首次部署时在原主机运行的隔离验证助手，依赖当时的暂存 SQL；成功后的暂存文件已清理，不应把它当成可直接重复执行的整站恢复命令。

## 部署代码与停用

脚本保存在仓库 `scripts/ops/elephant-backup/`；本次没有 Git 提交或推送。线上安装文件与对应本地脚本 SHA-256 核对一致：

| 本地文件 | 线上路径 |
| --- | --- |
| `source.py` | 香港 `/usr/local/sbin/elephant-backup-source` |
| `receiver.py` | 硅谷 `/usr/local/sbin/elephant-backup-receiver` |
| `download_site.py` | 硅谷 `/usr/local/sbin/elephant-backup-download-site` |
| `monitor.py` | 两端 `/usr/local/sbin/elephant-backup-monitor` |

需要暂停时，香港停 `elephant-backup.timer`，硅谷停 `elephant-download-site-backup.timer` 和 `elephant-backup-prune.timer`；根据实际维护计划决定是否继续监控。已有密文保留。不要删除稀疏盘文件、卸载仍在写入的文件系统，或直接删除专用用户后假定备份仍会运行。
