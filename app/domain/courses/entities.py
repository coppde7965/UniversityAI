from dataclasses import dataclass
from typing import Literal

CourseStatus = Literal["draft", "approved", "rejected"]

@dataclass
class Course:
    id: int
    title: str
    description: str
    credits: int
    status: CourseStatus = "draft"
