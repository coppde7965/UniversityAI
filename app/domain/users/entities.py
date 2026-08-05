from dataclasses import dataclass
from typing import Literal

UserRole = Literal["teacher", "student", "assistant", "admin"]

@dataclass
class User:
    id: int
    name: str
    role: UserRole = "student"