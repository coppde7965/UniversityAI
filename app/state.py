from datetime import datetime

TEACHER_ID = "teacher-fixed-id"
syllabi = {}

def create_syllabus_id() -> str:
    return f"syllabus-{datetime.now().strftime('%Y%m%d%H%M%S%f')}"
