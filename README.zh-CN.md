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

Identity Service 是一个基于 Laravel 13 的私有管理员控制台。账号与审计数据存放在 PostgreSQL 18，会话存放在 Redis。

服务不提供公开注册、自助找回密码或令牌 API，管理员只能从受信任的终端创建。

## 环境要求

- PHP 8.4.27 或更高版本；使用 PHP 8.5 时需 8.5.12 或更高，并启用 `pdo_pgsql` 与 `redis`
- Composer 2
- PostgreSQL 18
- Redis

## 快速开始

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

先在 `.env` 中填写连接信息，再由 Web 服务器提供 `public/` 目录并置于 TLS 之后。登录入口位于 `/admin/login`。

## 配置

`.env.example` 提供了可直接用于本地的取值；生产环境的凭据应放在平台的密钥管理服务中。

| 变量 | 用途 |
| --- | --- |
| `APP_KEY` | 加密会话数据，部署之间保持不变。 |
| `APP_URL` | 反向代理后的对外访问地址。 |
| `DB_*` | PostgreSQL 18 连接。 |
| `SESSION_DRIVER`、`SESSION_CONNECTION` | Redis 会话存储。 |
| `SESSION_LIFETIME` | 会话空闲有效期，单位为分钟。 |
| `SESSION_ENCRYPT`、`SESSION_SECURE_COOKIE` | 除本机 HTTP 开发外都应保持 `true`。 |
| `REDIS_*` | Redis 地址与凭据。 |
| `CACHE_STORE`、`REDIS_CACHE_DB` | Redis 缓存，使用独立数据库。 |
| `LOG_CHANNEL` | 生产环境使用 `stderr`。 |

## 管理员命令

| 命令 | 用途 |
| --- | --- |
| `php artisan identity:admin:bootstrap` | 创建首个管理员。 |
| `php artisan identity:admin:reset-password` | 为账号设置新密码。 |

两个命令都隐藏密码输入，也不接受密码参数。每次变更与对应的审计记录在同一事务中提交。

## 访问规则

- 只有已启用的管理员能进入 `/admin`，其他访问会被引导到 `/admin/login`。
- 登录限流：同一邮箱与地址每分钟 5 次，同一地址每分钟 25 次。
- 修改密码会让已有会话失效。
- 管理员不能禁用自己的账号，也不能禁用最后一个已启用的管理员。

## 测试

| 命令 | 作用 |
| --- | --- |
| `composer validate --strict` | 校验 Composer 清单。 |
| `vendor/bin/pint --test` | 检查代码格式。 |
| `vendor/bin/phpstan analyse --no-progress` | 以 Larastan level 5 执行静态分析。 |
| `composer test:coverage` | 运行测试，并要求 `app/` 行覆盖率达到 100%。 |
| `composer audit --locked --no-interaction` | 审计锁定版本的依赖。 |

测试需要独立的 PostgreSQL 18 角色与数据库：

```sql
CREATE ROLE identity_service_test LOGIN CREATEDB NOSUPERUSER;
```

参考 `.env.example` 创建 `.env.testing`，填入新的 `APP_KEY`、`TEST_DB_HOST` 与 `TEST_DB_PASSWORD`。测试守卫要求端口 `5432`，数据库与角色均为 `identity_service_test`。CI 要求 `app/` 的行覆盖率恰好为 100%。

## 部署与运维

- 以非 root 用户在 8080 端口运行并置于 TLS 之后，只信任 `bootstrap/app.php` 中明确列出的代理网段，不要使用 `*`。
- 会话与缓存使用不同的 Redis 数据库；`cache:clear` 会清空所在数据库，共用会让所有管理员退出登录。
- 用 `pg_dump` 备份，`APP_KEY` 不要放进备份，恢复后应轮换密钥。
- `/up` 用于判断是否需要重启，`/ready` 用于决定是否接收流量。
- 在 `main` 上推送 `vX.Y.Z` 标签即可发布。工作流会先运行 CI 与容器冒烟测试，再创建 Release；手动触发只预览说明。

## 许可证

项目代码采用 [MIT 许可证](LICENSE)。
