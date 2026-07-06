from abc import ABC, abstractmethod
from app.presentation.schemas.user import UserRead, UserCreate

class UserRepository(ABC):
    @abstractmethod
    def get_all(self) -> list[UserRead]:
        pass

    @abstractmethod
    def get_by_id(self, user_id: int) -> UserRead | None:
        pass

    @abstractmethod
    def create(self, user_data: UserCreate) -> UserRead:
        pass

    @abstractmethod
    def update(self, user_id: int, user_data: UserCreate) -> UserRead | None:
        pass

    @abstractmethod
    def delete(self, user_id: int) -> bool:
        pass