# UniversityAI

FastAPI 大學 AI 系統 - 練習用專案

## 功能

目前實現四個模組：

| 模組 | 說明 |
|------|------|
| users | 使用者管理 |
| schedules | 課程表管理 |
| courses | 課程管理 |
| notifications | 通知管理 |

## 啟動伺服器

```powershell
.venv\Scripts\Activate.ps1
python -m uvicorn main:app --host 127.0.0.1 --port 8002 --reload
```

## API 端點

### Users

| 方法 | 路徑 | 說明 |
|------|------|------|
| GET | `/users/` | 取得所有使用者 |
| GET | `/users/{id}` | 取得單一使用者 |
| POST | `/users/` | 新增使用者 |

測試範例：

```powershell
Invoke-RestMethod -Method Get -Uri http://127.0.0.1:8002/users/
Invoke-RestMethod -Method Get -Uri http://127.0.0.1:8002/users/1
Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8002/users/ -ContentType "application/json" -Body '{"name":"Alice","email":"alice@example.com"}'
```

### Schedules

| 方法 | 路徑 | 說明 |
|------|------|------|
| GET | `/schedules/` | 取得所有課程表 |
| GET | `/schedules/{id}` | 取得單一課程表 |
| POST | `/schedules/` | 新增課程表 |

測試範例：

```powershell
Invoke-RestMethod -Method Get -Uri http://127.0.0.1:8002/schedules/
Invoke-RestMethod -Method Get -Uri http://127.0.0.1:8002/schedules/1
Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8002/schedules/ -ContentType "application/json" -Body '{"title":"Math"}'
```

### Courses

| 方法 | 路徑 | 說明 |
|------|------|------|
| GET | `/courses/` | 取得所有課程 |
| GET | `/courses/{id}` | 取得單一課程 |
| POST | `/courses/` | 新增課程 |

測試範例：

```powershell
Invoke-RestMethod -Method Get -Uri http://127.0.0.1:8002/courses/
Invoke-RestMethod -Method Get -Uri http://127.0.0.1:8002/courses/1
Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8002/courses/ -ContentType "application/json" -Body '{"title":"Machine Learning","description":"Intro to ML","credits":4}'
```

### Notifications

| 方法 | 路徑 | 說明 |
|------|------|------|
| GET | `/notifications/` | 取得所有通知 |
| GET | `/notifications/{id}` | 取得單一通知 |
| POST | `/notifications/` | 新增通知 |
| PUT | `/notifications/{id}` | 更新通知 |
| DELETE | `/notifications/{id}` | 刪除通知 |

測試範例：

```powershell
Invoke-RestMethod -Method Get -Uri http://127.0.0.1:8002/notifications/
Invoke-RestMethod -Method Get -Uri http://127.0.0.1:8002/notifications/1
Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8002/notifications/ -ContentType "application/json" -Body '{"title":"Test Notification","message":"This is a test","is_read":false}'
Invoke-RestMethod -Method Put -Uri http://127.0.0.1:8002/notifications/1 -ContentType "application/json" -Body '{"title":"Updated Notification","message":"Updated message","is_read":true}'
Invoke-RestMethod -Method Delete -Uri http://127.0.0.1:8002/notifications/1
```

## 專案結構

```text
UniversityAI/
├─main.py              # 應用程式入口
├─README.md            # 說明文件
└─app/
  ├─domain/            # 領域實體 (entities)
  ├─infrastructure/    # 基礎設施 (Repository 實作)
  ├─application/       # 應用層 (Use Cases)
  └─presentation/      # 表現層 (API Routes、Schemas)
```

## 擴充

若要新增模組，照現有模式：

1. 新增 `domain/xxx/entities.py`
2. 新增 `infrastructure/xxx/memory_repository.py`
3. 新增 `application/xxx/repository.py` 和 `use_cases.py`
4. 新增 `presentation/api/xxx/routes.py`
5. 修改 `main.py` 註冊 router


## 測試（BDD）

`ash
pytest tests/
`

## n8n 整合

詳見 [N8N_INTEGRATION.md](N8N_INTEGRATION.md)

