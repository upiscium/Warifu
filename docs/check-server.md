# 稼働中サーバーを読み取り確認する

以下の `occ` と `curl` は設定・ファイルを書き換えません。値は実環境のものに置き換えます。

## サーバー設定

**実行場所：** NextcloudアプリサーバーのNextcloudルート。Dockerならアプリコンテナ内 `/var/www/html`。DBコンテナではありません。Web実行ユーザーで実行します。

```sh
NC_USER='ユーザーID'
php -r 'exit(PHP_INT_SIZE === 8 ? 0 : 1);'
php occ status
php occ config:app:get nextcloud_gateway installed_version
php occ app:list
php occ config:app:get nextcloud_gateway scope_mode
php occ config:app:get nextcloud_gateway size_limits_gib
php occ user:setting "$NC_USER" nextcloud_gateway allowed_roots
```

版は `0.3.1`、PHP確認は成功すること。scopeは `all`、`selected`、`off` のいずれかです。新規導入既定は `all`。更新では旧動作を引き継ぎ、更新で全体へ自動拡張しません。`allowed_roots` は内容条件の範囲でありACLではありません。アプリの1ファイル既定値は10 GiB、要求単位の累積ハッシュ予算は50 GiBで、それぞれ独立した管理者設定は1〜4294967295整数GiBです。設定が保存済みならその値を確認します。設定を確認するためだけに変更・保存しないでください。`config.php` やユーザーのバイト上限は引き上げません。

## 認証済みDAV読み取り確認

`size_limits_gib` が未設定なら初期値 `10`／`50`（GiB）が使われます。管理画面で保存した場合は `fileGiB`／`hashGiB` のJSONが返ります。`scope_mode` 未設定の旧環境では管理画面上の実効モードを確認してください。

**実行場所：** クライアントPCの作業ディレクトリ。curl 7.84以降。TLS検証を無効にせず、`--user` にパスワードを含めないでください（プロンプト入力）。

```sh
NC_USER='ユーザーID'
DAV="https://cloud.example.org/remote.php/dav/files/$NC_USER"
curl -sS -X PROPFIND -H 'Depth: 0' -w '%{http_code}\n' -o /dev/null "$DAV/WarifuTest/"
curl -sS --user "$NC_USER" -X PROPFIND -H 'Depth: 0' -w '%{http_code} gateway=%header{x-nextcloud-gateway}\n' -o /dev/null "$DAV/WarifuTest/"
curl -sS --user "$NC_USER" -I -o /dev/null -w '%{http_code} gateway=%header{x-nextcloud-gateway} etag=%header{etag}\n' "$DAV/WarifuTest/small.txt"
```

URL、ユーザー、パスを実在値に置換。典型的には匿名要求 `401`、認証済み許可フォルダ `207`。対象モードが有効ならファイル応答に `X-Nextcloud-Gateway: experimental-1` と `"ncgw-sha256-` で始まる64桁SHA-256 ETagが現れます。実応答は構成に依存し、`occ` 成功だけではHTTP適用を確認できません。Redis等のロック競合では実際に `423 Locked` が返ることがあります。
