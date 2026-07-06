import asyncio
from src.database.connection import init_db
from src.utils.cache import init_redis, close_redis
from src.services.news_service import ingest_all

async def main():
    init_db()
    await init_redis()
    print('Starting ingestion test...')
    new_articles = await ingest_all()
    print(f'Ingestion complete! Fetched {new_articles} new articles.')
    await close_redis()

if __name__ == '__main__':
    asyncio.run(main())
