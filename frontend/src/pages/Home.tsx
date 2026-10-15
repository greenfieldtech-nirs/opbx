import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { HomeHeader } from './Home/HomeHeader';
import { AnnouncementBanner } from './Home/AnnouncementBanner';
import { Hero } from './Home/Hero';
import { WhatIsOPBX } from './Home/WhatIsOPBX';
import { BuiltDifferent } from './Home/BuiltDifferent';
import { DograhSpotlight } from './Home/DograhSpotlight';
import { HowItWorks } from './Home/HowItWorks';
import { AIHandoff } from './Home/AIHandoff';
import { FeaturesGrid } from './Home/FeaturesGrid';
// import { ProjectStats } from './Home/ProjectStats'; // uncomment when real stats are ready
import { TechnologySection } from './Home/TechnologySection';
import { Pricing } from './Home/Pricing';
import { FAQSection } from './Home/FAQSection';
import { FinalCTA } from './Home/FinalCTA';
import { Footer } from './Home/Footer';

export default function Home() {
  const { hash } = useLocation();

  // Arriving from another page (e.g. /status -> /#pricing) the browser looks for the anchor before
  // the sections render, so scroll once they exist and again once web fonts settle the layout.
  useEffect(() => {
    if (!hash) return;
    const scroll = () => document.getElementById(decodeURIComponent(hash.slice(1)))?.scrollIntoView();
    const frame = requestAnimationFrame(scroll);
    document.fonts?.ready.then(scroll);
    return () => cancelAnimationFrame(frame);
  }, [hash]);

  return (
    <div className="min-h-screen bg-background text-foreground home-dark oranienbaum-regular">
      <AnnouncementBanner />
      <HomeHeader />
      <Hero />
      <WhatIsOPBX />
      <BuiltDifferent />
      <DograhSpotlight />
      <FeaturesGrid />
      {/* <ProjectStats /> — hidden until real statistics content is provided */}
      <HowItWorks />
      <AIHandoff />
      <TechnologySection />
      <Pricing />
      <FAQSection />
      <FinalCTA />
      <Footer />
    </div>
  );
}
