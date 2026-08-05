# UniversityAI

FastAPI + n8n 的課程管理練習專案。

## 功能

- 建立課程。
- 老師確認課程狀態。
- 課程狀態可切換為 `draft`、`approved`、`rejected`。
- 建立或確認課程時，會送 webhook 到 n8n。

## 技術

- FastAPI
- Pydantic
- requests
- n8n

## 啟動方式

先安裝套件：

```bash
pip install fastapi uvicorn requests
```

啟動 FastAPI：

```bash
uvicorn main:app --reload
```

## API

### 建立課程

`POST /courses/`

Body:

```json
{
  "title": "Biology",
  "description": "Basic Biology",
  "credits": 3
}
```

### 老師確認通過

`POST /courses/{course_id}/approve`

### 老師確認退回

`POST /courses/{course_id}/reject`

## n8n webhook

建立課程或確認課程後，系統會送出 webhook 事件：

- `course_created`
- `course_approved`
- `course_rejected`

測試時使用：

```text
http://localhost:5678/webhook-test/course-created
```

## 注意事項

- `main.py` 才是 FastAPI 程式檔。
- `README.md` 只是說明文件。
- 如果你把程式碼貼到 README，FastAPI 不會執行。

