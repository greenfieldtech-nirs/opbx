import { HomeHeader } from './Home/HomeHeader';
import { Hero } from './Home/Hero';
import { WhatIsOPBX } from './Home/WhatIsOPBX';
import { BuiltDifferent } from './Home/BuiltDifferent';
import { DograhSpotlight } from './Home/DograhSpotlight';
import { HowItWorks } from './Home/HowItWorks';
import { AIHandoff } from './Home/AIHandoff';
import { FeaturesGrid } from './Home/FeaturesGrid';
import { TechnologySection } from './Home/TechnologySection';
import { Pricing } from './Home/Pricing';
import { FAQSection } from './Home/FAQSection';
import { FinalCTA } from './Home/FinalCTA';
import { Footer } from './Home/Footer';

export default function Home() {
  return (
    <div className="min-h-screen bg-background text-foreground home-dark oranienbaum-regular">
      <HomeHeader />
      <Hero />
      <WhatIsOPBX />
      <BuiltDifferent />
      <DograhSpotlight />
      <FeaturesGrid />
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
