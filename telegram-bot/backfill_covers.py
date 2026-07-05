import asyncio
import os
import sys

# Ensure src module can be imported
sys.path.insert(0, os.path.abspath(os.path.dirname(__file__)))

from src.database.connection import session_scope
from sqlalchemy import select
from src.database.models import NewsArticle
from src.services.news_service import _generate_ai_cover
from src.config.settings import get_settings

async def main():
    settings = get_settings()
    print("========================================")
    print(f"AI Cover Backfill Script")
    print(f"Provider: {settings.ai_image_provider}")
    print(f"Target Directory: {settings.covers_dir}")
    print("========================================\n")
    
    print("Fetching up to 10 recent articles without covers...")
    with session_scope() as s:
        stmt = select(NewsArticle).where(NewsArticle.image_url.is_(None)).order_by(NewsArticle.published_at.desc()).limit(10)
        articles = list(s.scalars(stmt).all())
        
    if not articles:
        print("No articles missing images found.")
        return
        
    print(f"Found {len(articles)} articles. Generating covers...\n")
    
    updated_count = 0
    failed_count = 0
    for a in articles:
        print(f"Processing: {a.title[:80]}...")
        gen_result = await _generate_ai_cover(a.title, a.summary, a.url_hash)
        if gen_result:
            cover_url = gen_result["url"]
            print(f"✅ Success! {cover_url} (took {gen_result['time']:.1f}s)")
            with session_scope() as s:
                db_art = s.get(NewsArticle, a.id)
                db_art.image_url = cover_url
            updated_count += 1
        else:
            print("❌ Failed to generate cover.")
            failed_count += 1
            
    print(f"\n========================================")
    print(f"Backfill Complete!")
    print(f"  Generated: {updated_count}")
    print(f"  Failed:    {failed_count}")
    print(f"========================================")

if __name__ == "__main__":
    asyncio.run(main())
