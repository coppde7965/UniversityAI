from pydantic import BaseModel, Field


class CourseCreate(BaseModel):
    title: str = Field(..., min_length=1)
    description: str = Field(default="")
    credits: int = Field(default=3, ge=0)


class CourseResponse(BaseModel):
    id: int
    title: str
    description: str
    credits: int
