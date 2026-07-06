from pydantic import BaseModel, Field


class Course(BaseModel):
    id: int
    title: str = Field(..., min_length=1)
    description: str = Field(default="")
    credits: int = Field(default=3, ge=0)
