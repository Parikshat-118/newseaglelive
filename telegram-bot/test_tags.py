import asyncio
from datetime import datetime
from src.services.student_service import generate_content

async def run():
    print("Generating UPSC Quiz to test search_tags...")
    await generate_content("upsc_quiz", target_date=datetime.now(), language="en")
    print("Done!")

if __name__ == "__main__":
    asyncio.run(run())
