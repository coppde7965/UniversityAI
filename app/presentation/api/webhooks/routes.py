from fastapi import APIRouter, HTTPException, BackgroundTasks

router = APIRouter(prefix="/webhooks", tags=["webhooks"])

@router.post("/notify")
async def n8n_notify(data: dict, background_tasks: BackgroundTasks):
    """
    n8n 工作流呼叫這個 endpoint 來發送通知
    
    預期格式:
    {
        "title": "通知標題",
        "message": "通知內容",
        "course_id": 1,
        "is_urgent": true
    }
    """
    try:
        # TODO: 可以在這裡加上發送 Email 或 Push 的邏輯
        # background_tasks.add_task(send_email, notification)
        
        return {
            "status": "success",
            "notification_id": 1,
            "message": "通知已發送",
            "received_data": data
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@router.post("/schedule-reminder")
async def schedule_reminder(data: dict):
    """
    n8n 排程工作流呼叫這個 endpoint
    
    預期格式:
    {
        "schedule_id": 1,
        "reminder_time": "2024-01-01T10:00:00",
        "message": "上課提醒"
    }
    """
    return {
        "status": "success",
        "message": "排程提醒已設定",
        "received_data": data
    }
