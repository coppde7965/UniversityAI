from abc import ABC, abstractmethod
from typing import List, Optional

from app.domain.notifications.entities import Notification


class AbstractNotificationRepository(ABC):
    @abstractmethod
    def add(self, title: str, message: str = "", is_read: bool = False) -> Notification:
        pass

    @abstractmethod
    def get_all(self) -> List[Notification]:
        pass

    @abstractmethod
    def get_by_id(self, notification_id: int) -> Optional[Notification]:
        pass

    @abstractmethod
    def delete(self, notification_id: int) -> bool:
        pass

    @abstractmethod
    def update(self, notification_id: int, title: str, message: str = "", is_read: bool = False) -> Optional[Notification]:
        pass
