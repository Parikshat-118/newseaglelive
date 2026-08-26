import asyncio
import sys
import os

# Add the /app path so we can import src modules
sys.path.append('/app')

from src.services.student_service import generate_content
from datetime import date

async def main():
    today = date.today()
    print(f"Generating UPSC Quiz for {today} in English...")
    await generate_content('upsc_quiz', target_date=today, language='en')
    print("English quiz generation completed.")
    
    print(f"Generating UPSC Quiz for {today} in Hindi...")
    await generate_content('upsc_quiz', target_date=today, language='hi')
    print("Hindi quiz generation completed.")
    
    print("\nGeneration triggered successfully!")
    print("Please check the Student Hub 'UPSC/SSC Quiz' tab to see the results.")

if __name__ == "__main__":
    asyncio.run(main())
