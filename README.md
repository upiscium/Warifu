# Warifu for Nextcloud

Warifu（割符）は、Nextcloud 標準 Files WebDAV にファイル内容由来の SHA-256 ETag と条件付き更新を加えるアプリです。アプリIDは `nextcloud_gateway` です。

**v0.2.0-rc.1 は評価用の未署名リリース候補**です。正式版 v0.1.0 からの自動更新は行いません。

| 確認したいこと | 実行場所と手順 |
| --- | --- |
| 稼働中の版・登録先・SHA ETag | [サーバーでは `occ`、手元のPCでは `curl`](docs/check-server.md)。読み取りだけで確認できます |
| 試験サーバーへの新規導入 | [アーカイブの照合、配置、専用ユーザーでの有効化](docs/install-nextcloud.md) |
| v0.1.0 からの更新と復旧 | [書込み停止、バックアップ、`occ upgrade`、切戻し](docs/upgrade.md) |
| 50 GiBのファイル上限と1 GiBの累積ハッシュ上限の違い | [変更点と適用範囲](docs/rc-notes.md) |

想定ランタイムは Nextcloud **35.0.3** の固定公式 Apache イメージと PHP **8.5.12**、非暗号化のローカルホーム、対応する DB または Redis ロック構成です。対応範囲外のコア・ストレージ構成は適合済みとみなさないでください。
