# Warifu v0.3.0 を新規導入する

未署名の手動導入です。対象は Nextcloud 35.0.3、PHP 8.5.12、通常の非暗号化ローカルホームに限ります。外部ストレージ、暗号化、サブマウントは対象外です。復元可能なバックアップを事前に確認してください。

## アーカイブ照合と配置

**実行場所：** Linuxサーバー上のシェル。アーカイブと独立して入手したチェックサム情報があるディレクトリで実行します。Dockerでは永続化された `custom_apps` を使います。`APPDIR` は実環境の登録済みアプリディレクトリに変更してください。

```sh
set -eu
ARCHIVE="$PWD/nextcloud_gateway-0.3.0.tar.gz"
test "$(wc -c < "$ARCHIVE" | tr -d ' ')" = '41647'
printf '%s  %s\n' '09b93665a07bf2ac0e0f5a539a1007a2a6aa9d989e2418b151d0f50dca296fe5' "$ARCHIVE" | sha256sum --check -
sha256sum --check SHA256SUMS
APPDIR=/var/www/html/custom_apps
test -d "$APPDIR" && test ! -L "$APPDIR"
target="$APPDIR/nextcloud_gateway"
test ! -e "$target" && test ! -L "$target"
stage=$(mktemp -d "$APPDIR/.ncgw-stage.XXXXXXXX")
tar --no-same-owner -xzf "$ARCHIVE" -C "$stage"
test -f "$stage/nextcloud_gateway/appinfo/info.xml"
mv -nT -- "$stage/nextcloud_gateway" "$target"
test ! -e "$stage/nextcloud_gateway"
rmdir "$stage"
```

期待値：サイズが `41647` bytes、両方のチェックサム検査が `OK`。値が未置換、チェックサム不明・不一致、または配置先が既存なら停止します。既存ディレクトリへの展開・上書きはしません。未署名のチェックサムは配布元の真正性を証明しません。配置後はWeb実行ユーザーが読める所有者・権限にし、全ワーカーが同一コードを参照することを確認します。

## 有効化とモード

**実行場所：** Nextcloudアプリサーバーの Nextcloud ルート（Dockerならアプリコンテナ内 `/var/www/html`）。Web実行ユーザーで実行します。

```sh
php occ status
php occ app:enable nextcloud_gateway
php occ config:app:get nextcloud_gateway scope_mode
```

新規導入では出力が `all` であることを確認します。これは全ユーザーの通常ローカルホームが対象となる意味です。設定画面は **管理者設定 → 基本設定 → Warifu**（`/index.php/settings/admin/server`）です。`all`、`selected`、`off` を選べます。`selected` はユーザーごとの `allowed_roots` を内容条件の適用範囲として使います。

`allowed_roots` はアクセス制御やACLではありません。Nextcloud標準の権限を置き換えず、通常DAVアクセスを制限しません。設定後は[サーバー確認](check-server.md)で設定値とHTTP応答を個別に確認してください。

SHA ETag算出はファイル全体をハッシュするため、ファイルサイズ上限より先に要求単位の累積ハッシュ予算へ達する場合があります。大きなファイルの部分読込でも全体のハッシュが必要になり得ます。
