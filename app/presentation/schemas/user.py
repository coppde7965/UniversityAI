from pydantic import BaseModel
from typing import Literal

UserRole = Literal["teacher", "student", "assistant", "admin"]

class UserRead(BaseModel):
    id: int
    name: str
    role: UserRole

class UserCreate(BaseModel):
    name: str
    role: UserRole = "student"