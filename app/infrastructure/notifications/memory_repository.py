from typing import List, Optional

from app.domain.notifications.entities import Notification


class NotificationMemoryRepository:
    def __init__(self):
        self._notifications: dict[int, Notification] = {}
        self._counter: int = 0
        self._seed_data()

    def _seed_data(self):
        self.add("Welcome", "Welcome to UniversityAI!")
        self.add("System Update", "System will be updated tonight.")

    def add(self, title: str, message: str = "", is_read: bool = False) -> Notification:
        self._counter += 1
        notification = Notification(
            id=self._counter,
            title=title,
            message=message,
            is_read=is_read,
        )
        self._notifications[self._counter] = notification
        return notification

    def get_all(self) -> List[Notification]:
        return list(self._notifications.values())

    def get_by_id(self, notification_id: int) -> Optional[Notification]:
        return self._notifications.get(notification_id)

    def delete(self, notification_id: int) -> bool:
        if notification_id in self._notifications:
            del self._notifications[notification_id]
            return True
        return False

    def update(self, notification_id: int, title: str, message: str = "", is_read: bool = False) -> Optional[Notification]:
        if notification_id in self._notifications:
            notification = Notification(
                id=notification_id,
                title=title,
                message=message,
                is_read=is_read,
            )
            self._notifications[notification_id] = notification
            return notification
        return None
