from pydantic import BaseModel, Field


class NotificationCreate(BaseModel):
    title: str = Field(..., min_length=1)
    message: str = Field(default="")
    is_read: bool = Field(default=False)


class NotificationResponse(BaseModel):
    id: int
    title: str
    message: str
    is_read: bool
