from typing import List, Optional

from app.domain.notifications.entities import Notification
from app.application.notifications.repository import AbstractNotificationRepository


class NotificationUseCases:
    def __init__(self, repository: AbstractNotificationRepository):
        self.repository = repository

    def get_all_notifications(self) -> List[Notification]:
        return self.repository.get_all()

    def get_notification_by_id(self, notification_id: int) -> Optional[Notification]:
        return self.repository.get_by_id(notification_id)

    def create_notification(self, title: str, message: str = "", is_read: bool = False) -> Notification:
        return self.repository.add(title, message, is_read)

    def delete_notification(self, notification_id: int) -> bool:
        return self.repository.delete(notification_id)

    def update_notification(self, notification_id: int, title: str, message: str = "", is_read: bool = False) -> Optional[Notification]:
        return self.repository.update(notification_id, title, message, is_read)
