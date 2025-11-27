# notion-bookshelf-importer

Kindle 商品ページの URL を基に OpenAI API を用いてタイトル・価格・著者情報などを抽出し、Notion の本棚データベースへ登録するバックエンドアプリ。

## Webhook 仕様

Notion から呼び出される Laravel 製の webhook を `routes/api.php` に定義しています。`POST /api/webhook/notion/books` に対して以下の形式でリクエストしてください。

- ヘッダー: `X-Webhook-Key` に `.env` の `WEBHOOK_AUTH_KEY` と同じ値を設定（時刻署名不要、`hash_equals` で比較）。
- ボディ: JSON で `page_id`（Notion ページ ID）と `product_url`（本の URL）の 2 項目。

```json
{
  "page_id": "xxxx",
  "product_url": "https://www.amazon.co.jp/dp/..."
}
```

## 環境変数

`.env.example` をコピーして `.env` を用意してください。

- `OPENAI_API_KEY` / `OPENAI_MODEL`: OpenAI Chat Completions API への接続設定（デフォルトは `gpt-4o-mini`。アクセス権限がないモデルの場合は別モデルに差し替えてください）。
- `WEBHOOK_AUTH_KEY`: Notion からのリクエストヘッダーに設定する認証キー。
- `NOTION_API_KEY`: Notion 公式 API の統合トークン。
- `NOTION_DATA_SOURCE_ID`: Data Source ID（データベース ID ではなく data source を指定）。Unique ID プロパティなどから page_id を引くために data source を Query する際に利用します。Webhook で page_id が渡される場合は未設定でも更新処理は動作します。
- `NOTION_VERSION`: API バージョン。仕様通り `2025-09-03` をデフォルトに設定。
- `NOTION_BASE_URL`: Notion API のベース URL（`https://api.notion.com/v1` のようにパスなしで設定し、`/pages` などのサフィックスは付けない）。
- `NOTION_PROPERTY_MAPPING`: Webhook で更新するプロパティのマッピング JSON。キー名が抽出結果のキー、`name` が Notion 側のプロパティ名、`type` がプロパティ種別（`title` / `select` / `date` / `number` / `image`）。

### ローカルテスト用サンプル JSON

リポジトリ直下の `samples/` にローカルでの動作確認に使えるサンプル JSON を用意しています。

- `webhook_request.json`: `POST /api/webhook/notion/books` に送信する想定のリクエストボディ。
- `extraction_response.json`: OpenAI からの抽出結果サンプル（`name` / `author` / `price` / `image`）。
- `property_mapping.json`: `.env` の `NOTION_PROPERTY_MAPPING` に設定できるサンプルマッピング。

## 処理フロー

1. `NotionWebhookController` でヘッダー認証とバリデーションを実施。
2. `BookExtractionService` が OpenAI API に対し `response_format: json_object` を指定して書誌情報を抽出。返却キーは `name` / `author` / `price` / `image` に固定。
3. `NotionService` が `NOTION_PROPERTY_MAPPING` を基にプロパティ payload を構築し、`PATCH /pages/{page_id}` で Notion に反映。

### Notion ページの更新フロー（2025-09-03 版）

Notion のレコード更新は page_id に対してのみ行われます。Unique ID などのプロパティから page_id を求める場合は以下の 2 ステップで対応してください。

1. `POST /v1/data_sources/{data_source_id}/query` で ID プロパティをフィルタに指定して対象ページを検索し、レスポンスに含まれる `results[*].id` から page_id を取得する。
2. 取得した page_id に対して `PATCH /v1/pages/{page_id}` を呼び、`properties` に更新内容を渡す。

## プロパティの更新ロジック

- title: Plain text で上書き。
- select: 該当オプションが存在しない場合 Notion 側で自動生成されます。
- date: `YYYY-MM-DD` 形式で指定してください。
- number: 数値（例: 1800.0）。
- image: 外部 URL のまま `files` プロパティとして登録します（画像バイナリは送信しません）。

## エラーハンドリング

- 認証エラーは 401 を返します。
- OpenAI/Notion へのリクエストが失敗した場合は例外を投げ、Laravel の例外ハンドラ経由でエラー応答します。ログにもレスポンス body を出力します。
