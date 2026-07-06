from abc import ABC, abstractmethod
from app.presentation.schemas.schedule import ScheduleRead, ScheduleCreate

class ScheduleRepository(ABC):
    @abstractmethod
    def get_all(self) -> list[ScheduleRead]:
        pass

    @abstractmethod
    def get_by_id(self, schedule_id: int) -> ScheduleRead | None:
        pass

    @abstractmethod
    def create(self, schedule_data: ScheduleCreate) -> ScheduleRead:
        pass

    @abstractmethod
    def update(self, schedule_id: int, schedule_data: ScheduleCreate) -> ScheduleRead | None:
        pass

    @abstractmethod
    def delete(self, schedule_id: int) -> bool:
        pass
