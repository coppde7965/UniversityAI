from fastapi import APIRouter, HTTPException
from app.application.schedules.use_cases import (
    get_schedules,
    get_schedule_by_id,
    create_schedule,
    update_schedule,
    delete_schedule,
)
from app.infrastructure.schedules.memory_repository import InMemoryScheduleRepository
from app.presentation.schemas.schedule import ScheduleRead, ScheduleCreate

router = APIRouter(prefix="/schedules", tags=["schedules"])
repository = InMemoryScheduleRepository()

@router.get("/", response_model=list[ScheduleRead])
def list_schedules():
    return get_schedules(repository)

@router.get("/{schedule_id}", response_model=ScheduleRead)
def get_schedule(schedule_id: int):
    schedule = get_schedule_by_id(schedule_id, repository)
    if not schedule:
        raise HTTPException(status_code=404, detail="Schedule not found")
    return schedule

@router.post("/", response_model=ScheduleRead)
def add_schedule(schedule_data: ScheduleCreate):
    return create_schedule(schedule_data, repository)

@router.put("/{schedule_id}", response_model=ScheduleRead)
def modify_schedule(schedule_id: int, schedule_data: ScheduleCreate):
    schedule = update_schedule(schedule_id, schedule_data, repository)
    if not schedule:
        raise HTTPException(status_code=404, detail="Schedule not found")
    return schedule

@router.delete("/{schedule_id}")
def remove_schedule(schedule_id: int):
    success = delete_schedule(schedule_id, repository)
    if not success:
        raise HTTPException(status_code=404, detail="Schedule not found")
    return {"message": f"Schedule {schedule_id} deleted"}
