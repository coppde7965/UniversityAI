from pydantic import BaseModel, Field


class Notification(BaseModel):
    id: int
    title: str = Field(..., min_length=1)
    message: str = Field(default="")
    is_read: bool = Field(default=False)
