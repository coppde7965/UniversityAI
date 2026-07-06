from fastapi import APIRouter, HTTPException
from app.application.notifications.use_cases import NotificationUseCases
from app.infrastructure.notifications.memory_repository import NotificationMemoryRepository
from app.presentation.schemas.notification import NotificationCreate, NotificationResponse

router = APIRouter(prefix="/notifications", tags=["notifications"])
repository = NotificationMemoryRepository()
use_cases = NotificationUseCases(repository)

@router.get("/", response_model=list[NotificationResponse])
def list_notifications():
    return use_cases.get_all_notifications()

@router.get("/{notification_id}", response_model=NotificationResponse)
def get_notification(notification_id: int):
    notification = use_cases.get_notification_by_id(notification_id)
    if not notification:
        raise HTTPException(status_code=404, detail="Notification not found")
    return notification

@router.post("/", response_model=NotificationResponse, status_code=201)
def add_notification(notification_data: NotificationCreate):
    return use_cases.create_notification(
        title=notification_data.title,
        message=notification_data.message,
        is_read=notification_data.is_read,
    )

@router.put("/{notification_id}", response_model=NotificationResponse)
def modify_notification(notification_id: int, notification_data: NotificationCreate):
    notification = use_cases.update_notification(
        notification_id=notification_id,
        title=notification_data.title,
        message=notification_data.message,
        is_read=notification_data.is_read,
    )
    if not notification:
        raise HTTPException(status_code=404, detail="Notification not found")
    return notification

@router.delete("/{notification_id}")
def remove_notification(notification_id: int):
    success = use_cases.delete_notification(notification_id)
    if not success:
        raise HTTPException(status_code=404, detail="Notification not found")
    return {"message": f"Notification {notification_id} deleted"}
