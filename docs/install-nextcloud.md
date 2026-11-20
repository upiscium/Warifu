# Warifu v0.3.1-rc.1 を新規導入する

未署名の手動導入です。対象は Nextcloud 35.0.3、64-bit PHP 8.5.12、通常の非暗号化ローカルホームに限ります。外部ストレージ、暗号化、サブマウントは対象外です。復元可能なバックアップを事前に確認してください。

## アーカイブ照合と配置

**実行場所：** Linuxサーバー上のシェル。アーカイブと独立して入手したチェックサム情報があるディレクトリで実行します。Dockerでは永続化された `custom_apps` を使います。`APPDIR` は実環境のアプリディレクトリに変更します。

```sh
set -eu
ARCHIVE="$PWD/nextcloud_gateway-0.3.1-rc.1.tar.gz"
test "$(wc -c < "$ARCHIVE" | tr -d ' ')" = '43107'
printf '%s  %s\n' '264c01fc34585abbfbfc60bb9d1a8bb894644a9fc68feeedb31bcdb09ffc8840' "$ARCHIVE" | sha256sum --check -
sha256sum --check SHA256SUMS
APPDIR=/var/www/html/custom_apps
test -d "$APPDIR"
test ! -L "$APPDIR"
target="$APPDIR/nextcloud_gateway"
test ! -e "$target"
test ! -L "$target"
stage=$(mktemp -d "$APPDIR/.ncgw-stage.XXXXXXXX")
tar --no-same-owner -xzf "$ARCHIVE" -C "$stage"
test -f "$stage/nextcloud_gateway/appinfo/info.xml"
mv -nT -- "$stage/nextcloud_gateway" "$target"
test ! -e "$stage/nextcloud_gateway"
rmdir "$stage"
```

期待値：サイズが `43107` bytes、両チェックサムが `OK`。値が未置換、照合不能・不一致、配置先が既存なら中止します。未署名チェックサムは真正性を証明しません。配置後にWeb実行ユーザーの読取権限と全ワーカーの共有コードを確認します。

## 有効化

**実行場所：** Nextcloudルート（Dockerならアプリコンテナ内 `/var/www/html`）、Web実行ユーザー。

```sh
php -r 'exit(PHP_INT_SIZE === 8 ? 0 : 1);'
php occ status
php occ app:enable nextcloud_gateway
php occ config:app:get nextcloud_gateway scope_mode
```

PHP確認が成功し、初回導入の `scope_mode` が `all` であることを確認します。管理画面は **管理者設定 → 基本設定 → Warifu**（`/index.php/settings/admin/server`）。ファイル上限と累積ハッシュ予算は別々に設定でき、整数GiBで1〜4294967295です。アプリ設定は `config.php` やユーザーのバイト上限を上げません。既存に明示された1ファイル上限が10 GiBを超える場合、新しい既定値で実効上限が低くなることがあります。

設定とDAV応答は[サーバー確認](check-server.md)を参照してください。`allowed_roots` はACLではありません。
