from app.application.schedules.repository import ScheduleRepository
from app.presentation.schemas.schedule import ScheduleRead, ScheduleCreate

class InMemoryScheduleRepository(ScheduleRepository):
    def __init__(self):
        self.schedules = [
            ScheduleRead(id=1, title="Math"),
            ScheduleRead(id=2, title="Science"),
        ]

    def get_all(self) -> list[ScheduleRead]:
        return self.schedules

    def get_by_id(self, schedule_id: int) -> ScheduleRead | None:
        for schedule in self.schedules:
            if schedule.id == schedule_id:
                return schedule
        return None

    def create(self, schedule_data: ScheduleCreate) -> ScheduleRead:
        new_id = max([schedule.id for schedule in self.schedules], default=0) + 1
        new_schedule = ScheduleRead(id=new_id, title=schedule_data.title)
        self.schedules.append(new_schedule)
        return new_schedule

    def update(self, schedule_id: int, schedule_data: ScheduleCreate) -> ScheduleRead | None:
        for index, schedule in enumerate(self.schedules):
            if schedule.id == schedule_id:
                updated_schedule = ScheduleRead(id=schedule_id, title=schedule_data.title)
                self.schedules[index] = updated_schedule
                return updated_schedule
        return None

    def delete(self, schedule_id: int) -> bool:
        for index, schedule in enumerate(self.schedules):
            if schedule.id == schedule_id:
                del self.schedules[index]
                return True
        return False
