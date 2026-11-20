# Warifu v0.2.0-rc.1 を新規導入する

この候補は未署名です。対象は [README](../README.md) 記載の固定ランタイムと対応ローカルストレージに限ります。専用の非管理者ユーザーと小さな専用フォルダを用意し、バックアップの復元可能性を事前確認してください。

## アーカイブ確認と配置

**実行場所：** GNU/Linuxで、配布アーカイブと `SHA256SUMS` を置いたディレクトリ。実際のアプリ配置先が見えるシェルで実行します（Dockerなら永続化された `custom_apps`）。下の照合で対象ファイルが `OK` になってから配置します。

```sh
set -eu
ARCHIVE="$PWD/nextcloud_gateway-0.2.0-rc.1.tar.gz"
sha256sum --check SHA256SUMS
APPDIR=/var/www/html/custom_apps  # 実際の登録済み・永続化されたアプリディレクトリ
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

`SHA256SUMS` は候補アーカイブ名の行を含み、その照合が成功することを確認します。照合不能・不一致・配置先の競合なら停止し、上書き、`--force`、既存ディレクトリへの展開はしません。Web実行ユーザーから読める所有者・権限を設定します。全ワーカーが同一コードを見ることを確認してください。

## 有効化

NextcloudルートでWeb実行ユーザーとして実行します。フラグを最後に有効化します。

```sh
php occ status
php occ app:enable nextcloud_gateway
NC_USER='専用ユーザーID'
php occ user:setting "$NC_USER" nextcloud_gateway allowed_roots '["WarifuTrial"]'
php occ user:setting "$NC_USER" nextcloud_gateway allowed_roots
php occ config:app:set nextcloud_gateway experimental_enabled --value=yes
```

専用ユーザーのFiles画面で `WarifuTrial` を作成しておきます。`allowed_roots` はアクセス制御ではありません。App Passwordを発行し、HTTPSでDAVを接続します。TLS検証を無効化せず、パスワードをコマンドや履歴に含めません。

## 受入と切戻し

まず [読み取り専用確認](check-server.md) を行います。次に、実データでなく新規の合成ファイルだけを使って条件付きPUTと古いETagによる412を確認し、本文を読み戻します。想定外ならwriterを再開しないでください。

機能のみ戻す場合はwriter停止後 `php occ config:app:set nextcloud_gateway experimental_enabled --value=no` を実行し、全ワーカーへ反映されたことを認証済みDAVで確認します。ETag方式が変わるためクライアントにvalidatorを再取得させます。アプリ無効化はデータを以前の内容に戻しません。
