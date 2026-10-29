<h1 align="center">Identity Service</h1>

<p align="center">
  <a href="https://github.com/haoyuqi/identity-service/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/haoyuqi/identity-service/actions/workflows/ci.yml/badge.svg?branch=main"></a>
  <a href="https://github.com/haoyuqi/identity-service/actions/workflows/container-smoke.yml"><img alt="容器冒烟测试" src="https://github.com/haoyuqi/identity-service/actions/workflows/container-smoke.yml/badge.svg?branch=main"></a>
  <a href="https://laravel.com/docs/13.x"><img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white"></a>
  <a href="https://github.com/haoyuqi/identity-service/releases/latest"><img alt="最新版本" src="https://img.shields.io/github/release/haoyuqi/identity-service.svg"></a>
  <a href="LICENSE"><img alt="MIT 许可证" src="https://img.shields.io/badge/license-MIT-blue.svg"></a>
</p>

<p align="center">
  <a href="README.md">English</a> | 简体中文
</p>

一个用于私有管理账号的独立 Laravel 13 服务。仓库是公开的；应用以私有方式运行，不提供注册或 Token API。

## 环境要求

- PHP 8.4.27 或更高版本，或 8.5.12 及以上，并安装 `pdo_pgsql` 与 `redis` 扩展
- Composer 2
- PostgreSQL 18
- Redis，用于管理员会话

## 快速开始

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

在 `.env` 中设置 PostgreSQL 与 Redis 连接。`GET /up` 只证明进程能够响应请求，不会访问这两个依赖。

## 配置

| 变量 | 用途 |
| --- | --- |
| `APP_KEY` | 加密会话的静态密钥。正常部署之间保持不变。 |
| `APP_URL` | 对外 HTTPS 源地址。 |
| `DB_*` | PostgreSQL 18 连接。 |
| `SESSION_DRIVER`、`SESSION_CONNECTION` | `redis`，使用 `default` Redis 连接。 |
| `SESSION_LIFETIME`、`SESSION_ENCRYPT`、`SESSION_SECURE_COOKIE` | 生产环境为 `120`、`true`、`true`。 |
| `REDIS_HOST`、`REDIS_PORT`、`REDIS_PASSWORD` | Redis 地址与凭据。 |
| `CACHE_STORE`、`REDIS_CACHE_DB` | `redis`；缓存使用 `cache` 连接及独立数据库。 |
| `LOG_CHANNEL` | 生产环境为 `stderr`。 |

## 管理员命令

```sh
php artisan identity:admin:bootstrap       # 创建首个管理员；已存在启用管理员时拒绝
php artisan identity:admin:reset-password  # 重置账号密码
```

两个命令都通过隐藏的确认输入读取密码，不接受密码参数，并在同一事务内提交账号变更与审计记录。

## 访问规则

- 在 `/admin/login` 登录；只有启用的管理员能进入 `/admin`。
- 登录限制为：同一邮箱与地址组合每分钟 5 次，同一地址每分钟 25 次。
- 修改密码会使已有会话失效；这依赖 Redis 会话存储。
- 管理员不能禁用自己的账号，也不能禁用最后一个已启用的管理员。

## 验证

```sh
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
composer test:coverage
composer audit --locked --no-interaction
```

测试需要 PostgreSQL 18，以及专用的角色与数据库：

```sql
CREATE ROLE identity_service_test LOGIN CREATEDB NOSUPERUSER;
```

创建被忽略的 `.env.testing`，填入新的 `APP_KEY`、`TEST_DB_HOST`（`127.0.0.1` 或 `postgres`）与 `TEST_DB_PASSWORD`。守卫要求端口 `5432`、数据库与角色均为 `identity_service_test`、服务端为 PostgreSQL 18，且数据库由该角色拥有。会话测试使用 `REDIS_TEST_*` 配置的独立命名空间，并且从不执行 flush。

CI 要求 `app/` 的行覆盖率恰好为 100%；`composer test:coverage` 在本地执行同一门禁。

## 运维

- 镜像以非 root 用户在 8080 端口运行并置于 TLS 之后，只信任明确的代理 CIDR：`$middleware->trustProxies(at: ['10.20.0.0/16'])`，不要使用 `*`。
- 会话与缓存必须使用不同的 Redis 数据库：`cache:clear` 会执行 `FLUSHDB`，删除全部管理员会话。
- 使用 `pg_dump` 备份，先恢复到独立数据库再切换。`APP_KEY` 不能放进数据库备份；恢复后应轮换密钥，并从不含旧数据的会话命名空间重新开始。
- `/up` 用于决定是否重启，`/ready` 用于决定是否放流量。
- 在 `main` 上推送 `vX.Y.Z` 标签即发布；手动触发只生成预览说明。

## 许可证

项目代码采用 MIT 许可证。第三方内容保留各自权利。
