from app.application.schedules.repository import ScheduleRepository
from app.presentation.schemas.schedule import ScheduleRead, ScheduleCreate

def get_schedules(repository: ScheduleRepository) -> list[ScheduleRead]:
    return repository.get_all()

def get_schedule_by_id(schedule_id: int, repository: ScheduleRepository) -> ScheduleRead | None:
    return repository.get_by_id(schedule_id)

def create_schedule(schedule_data: ScheduleCreate, repository: ScheduleRepository) -> ScheduleRead:
    return repository.create(schedule_data)

def update_schedule(schedule_id: int, schedule_data: ScheduleCreate, repository: ScheduleRepository) -> ScheduleRead | None:
    return repository.update(schedule_id, schedule_data)

def delete_schedule(schedule_id: int, repository: ScheduleRepository) -> bool:
    return repository.delete(schedule_id)
