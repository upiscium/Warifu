# 稼働中サーバーを読み取り専用で確認する

以下は設定・ファイルを書き換えません。`occ` は管理者が Nextcloud ルートで Web 実行ユーザーとして実行します。Docker は Nextcloud アプリコンテナ（DBコンテナではない）の `/var/www/html` がルートです。

Dockerの場合はホストの任意のディレクトリで `docker exec -it -w /var/www/html --user www-data "$APP" sh`（`APP` は確認済みのアプリコンテナ名）。そのシェルで以下を実行します。

```sh
NC_USER='gateway-trial'   # 作成済みの専用ユーザーのUIDに置換
php occ status
php occ config:app:get nextcloud_gateway installed_version
php occ app:list
php occ config:app:get nextcloud_gateway experimental_enabled
php occ user:setting "$NC_USER" nextcloud_gateway allowed_roots
```

`status` は `installed: true`、`maintenance: false`、`needsDbUpgrade: false` を確認します。候補版が導入済みなら版は `0.2.0-rc.1`、アプリは Enabled、機能フラグは `yes`、登録フォルダは意図した値であることを確認します。出力が異なる場合は設定変更せず、管理者に確認してください。

## 読み取り専用DAV確認

クライアントPCの任意のディレクトリで curl 7.84 以降を使います。ホスト名、UID、登録フォルダとファイル名を実在する値に置換してください（例はASCII名）。プロンプトで App Password を入力します。

```sh
NC_USER='gateway-trial'
DAV="https://cloud.example.org/remote.php/dav/files/$NC_USER"
curl -sS -X PROPFIND -H 'Depth: 0' -w '%{http_code}\n' -o /dev/null "$DAV/WarifuTrial/"
curl -sS --user "$NC_USER" -X PROPFIND -H 'Depth: 0' -w '%{http_code} gateway=%header{x-nextcloud-gateway}\n' -o /dev/null "$DAV/WarifuTrial/"
curl -sS --user "$NC_USER" -I -o /dev/null -w '%{http_code} gateway=%header{x-nextcloud-gateway} etag=%header{etag}\n' "$DAV/WarifuTrial/small.txt"
```

匿名要求は401、認証済み登録フォルダは207かつ `experimental-1`、ファイルHEADは200で `"ncgw-sha256-` に続く64桁のSHA-256 ETagが期待値です。HTTP 207やoccの成功だけでは機能適用の確認になりません。この確認は書込み受入試験の代用ではありません。
