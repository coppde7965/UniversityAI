# n8n 整合說明

## Webhook 端點

### 1. 發送通知
**POST** `/webhooks/notify`

**請求格式:**
```json
{
    "title": "通知標題",
    "message": "通知內容",
    "course_id": 1,
    "is_urgent": true
}
```

**回應格式:**
```json
{
    "status": "success",
    "notification_id": 1,
    "message": "通知已發送",
    "received_data": {
        "title": "通知標題",
        "message": "通知內容",
        "course_id": 1,
        "is_urgent": true
    }
}
```

### 2. 排程提醒
**POST** `/webhooks/schedule-reminder`

**請求格式:**
```json
{
    "schedule_id": 1,
    "reminder_time": "2024-01-01T10:00:00",
    "message": "上課提醒"
}
```

**回應格式:**
```json
{
    "status": "success",
    "message": "排程提醒已設定",
    "received_data": {
        "schedule_id": 1,
        "reminder_time": "2024-01-01T10:00:00",
        "message": "上課提醒"
    }
}
```

## n8n 工作流範例

### 建立 HTTP Request 節點
1. 設定 Method: **POST**
2. 設定 URL: `http://localhost:8002/webhooks/notify`
3. 設定 Body: **JSON**
4. 填入通知資料

## 測試

### 用 curl 測試:
```bash
curl -X POST http://127.0.0.1:8002/webhooks/notify `
  -H "Content-Type: application/json" `
  -d "{\"title\":\"Test\",\"message\":\"Test Message\",\"course_id\":1}"
```

### 用 PowerShell 測試:
```powershell
Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8002/webhooks/notify" `
  -ContentType "application/json" `
  -Body '{"title":"n8n Test","message":"Test from n8n","course_id":1}'
```
