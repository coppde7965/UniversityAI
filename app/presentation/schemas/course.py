from pydantic import BaseModel
from typing import Literal

CourseStatus = Literal["draft", "approved", "rejected"]

class CourseCreate(BaseModel):
    title: str
    description: str
    credits: int

class CourseResponse(BaseModel):
    id: int
    title: str
    description: str
    credits: int
    status: CourseStatus
