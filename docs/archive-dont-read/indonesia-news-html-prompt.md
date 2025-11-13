# Compact Prompt — Indonesian News (HTML Output)

**Goal:** Generate a concise, up-to-date Indonesian news article in **HTML only** (no Markdown), optimized for low token usage.

**Variables to fill before running:**  
`{topic}` · `{audience}` · `{language}` (e.g., "Bahasa Indonesia" or "English") · `{date}` (YYYY-MM-DD Asia/Jakarta)

---

**COPY THIS PROMPT INTO YOUR AI:**

You are an SEO strategist from Blackbird Media Singapore. Write a factual, neutral **news article** about **{topic}** for **{audience}** in **{language}**. Use Asia/Jakarta time. Date context: **{date}**.

**Strict output rules (to keep tokens low):**
- **HTML only**, no explanations.
- Target **≈600–900 words** (hard cap ~1,000). Short sentences, no fluff.
- Use only these sections and tags—nothing extra.

```
<article>
  <header>
    <h1><!-- SEO title with primary keyword --></h1>
    <p><strong>Updated:</strong> {date} (Asia/Jakarta)</p>
    <p><em>By Blackbird Media Singapore – SEO Insights</em></p>
  </header>

  <section>
    <h2>Ringkasan / Key Takeaways</h2>
    <ul>
      <li><!-- Point 1 --></li>
      <li><!-- Point 2 --></li>
      <li><!-- Point 3 --></li>
    </ul>
  </section>

  <section>
    <h2>Apa yang Terjadi / What Happened</h2>
    <p><!-- 2–4 sentences: who/what/when/where --></p>
    <blockquote>
      <p>"<!-- Short attributed quote -->"</p>
      <cite><!-- Name, Role, Organization --></cite>
    </blockquote>
  </section>

  <section>
    <h2>Mengapa Penting / Why It Matters</h2>
    <ol>
      <li><!-- Impact angle 1 --></li>
      <li><!-- Impact angle 2 --></li>
      <li><!-- Impact angle 3 --></li>
    </ol>
  </section>

  <section>
    <h2>Angka Singkat / Numbers at a Glance</h2>
    <table>
      <thead>
        <tr>
          <th>Metric</th>
          <th>Latest</th>
          <th>Prev/Benchmark</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <tr><td><!-- e.g., Inflation --></td><td><!-- value --></td><td><!-- comp --></td><td><!-- source/date --></td></tr>
        <tr><td><!-- e.g., IDR/USD --></td><td><!-- value --></td><td><!-- comp --></td><td><!-- date --></td></tr>
      </tbody>
    </table>
  </section>

  <section>
    <h2>Timeline</h2>
    <ul>
      <li><strong><!-- YYYY-MM-DD --></strong> — <!-- Event --></li>
      <li><strong><!-- YYYY-MM-DD --></strong> — <!-- Event --></li>
      <li><strong><!-- YYYY-MM-DD --></strong> — <!-- Event --></li>
    </ul>
  </section>

  <section>
    <h2>FAQ</h2>
    <h3><!-- Q1 --></h3><p><!-- A1 --></p>
    <h3><!-- Q2 --></h3><p><!-- A2 --></p>
  </section>

  <section>
    <h2>Sumber / Sources</h2>
    <ul>
      <li><a href="<!-- url1 -->" rel="noopener nofollow"><!-- Publisher 1 --></a> — <!-- Title -->, published <!-- YYYY-MM-DD --></li>
      <li><a href="<!-- url2 -->" rel="noopener nofollow"><!-- Publisher 2 --></a> — <!-- Title -->, published <!-- YYYY-MM-DD --></li>
    </ul>
    <p><em>Label figures as "estimate" if unverified.</em></p>
  </section>

  <footer>
    <p>&copy; <!-- Year --> Blackbird Media Singapore. All rights reserved.</p>
  </footer>
</article>
```

**SEO (implicit, do not print):** primary keyword in H1 and first 100 words; Indonesia context (rupiah, ministries, provinces); keep paragraphs 2–4 sentences.