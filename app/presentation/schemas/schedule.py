from pydantic import BaseModel

class ScheduleRead(BaseModel):
    id: int
    title: str

class ScheduleCreate(BaseModel):
    title: str