<?php
require_once 'db/dbcon.php';

// 1. Fetch Dynamic Hero Pictures
$stmtPics = $pdo->query("SELECT * FROM website_content WHERE type = 'Pictures' AND status = 'Active' ORDER BY created_at DESC LIMIT 5");
$carouselPics = $stmtPics->fetchAll();

// 2. Fetch Dynamic Marquee Advisories
$stmtMarquee = $pdo->query("SELECT * FROM website_content WHERE type IN ('Announcement', 'Interruption', 'Rate') AND status = 'Active' ORDER BY created_at DESC LIMIT 10");
$marqueeItems = $stmtMarquee->fetchAll();

// 3. Fetch Dynamic Articles (News and Blogs)
$stmtNews = $pdo->query("SELECT * FROM website_content WHERE type IN ('News', 'Blog', 'Articles') AND status = 'Active' ORDER BY created_at DESC LIMIT 3");
$newsItems = $stmtNews->fetchAll();

// 4. INTELLIGENT RATE CALCULATION (From Database Catalog)
// Sum all active "Per_KWH" charges to get the exact total effective rate
$stmtTotalRate = $pdo->query("SELECT SUM(current_rate) as total_rate FROM billing_rates_catalog WHERE status = 'Active' AND charge_type = 'Per_KWH'");
$rateRow = $stmtTotalRate->fetch();
$displayRate = $rateRow['total_rate'] ? number_format((float)$rateRow['total_rate'], 4) : '0.0000';

// Helper function to fetch individual charge components for the breakdown
function getRateVal($pdo, $desc) {
    $stmt = $pdo->prepare("SELECT current_rate FROM billing_rates_catalog WHERE charge_description = ? AND status = 'Active'");
    $stmt->execute([$desc]);
    $val = $stmt->fetchColumn();
    return $val ? number_format((float)$val, 4) : '0.0000';
}

$genCharge = getRateVal($pdo, 'Generation System Charge');
$transCharge = getRateVal($pdo, 'Transmission System Charge');
$sysLossCharge = getRateVal($pdo, 'System Loss Charge');
$distCharge = getRateVal($pdo, 'Distribution System Charge');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOCECO | Negros Occidental Electric Cooperative</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              noceco: { bg: '#F5F5F7', text: '#1D1D1F', mustard: '#DBA111', mustardHover: '#B8860B' }
            },
            fontFamily: { sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'] },
            boxShadow: { 'apple': '0 8px 32px rgba(0, 0, 0, 0.08)', 'apple-sm': '0 2px 12px rgba(0, 0, 0, 0.04)' }
          }
        }
      }
    </script>
    <style>
        html { scroll-behavior: smooth; }
        
        /* Carousel */
        .carousel-slide { opacity: 0; transition: opacity 1s ease-in-out, transform 1s ease-in-out; transform: scale(1.05); }
        .carousel-slide.active { opacity: 1; transform: scale(1); z-index: 10; }
        
        /* Marquee */
        .marquee-container { overflow: hidden; white-space: nowrap; display: flex; align-items: center; }
        .marquee-content { display: inline-block; animation: marquee 25s linear infinite; }
        .marquee-content:hover { animation-play-state: paused; }
        @keyframes marquee { 0% { transform: translateX(10%); } 100% { transform: translateX(-100%); } }

        /* Accordion */
        .accordion-content { transition: max-height 0.3s ease-out, padding 0.3s ease; max-height: 0; overflow: hidden; }
        .accordion-content.open { max-height: 500px; padding-bottom: 1rem; }

        /* Modal Blur Fixes */
        body.modal-open { overflow: hidden; }
    </style>
</head>
<body class="bg-white text-noceco-text overflow-x-hidden antialiased">

    <nav class="fixed top-0 left-0 right-0 z-40 bg-white/90 backdrop-blur-xl border-b border-gray-200 transition-all duration-300" id="navbar">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <a href="#home" class="flex items-center gap-3 group">
                    <div class="w-10 h-10 rounded-full bg-noceco-mustard flex items-center justify-center shadow-apple-sm transition-transform group-hover:scale-105">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </div>
                    <span class="font-black text-2xl tracking-tighter">NOCECO</span>
                </a>

                <div class="hidden lg:flex items-center space-x-8 text-sm font-bold text-gray-400 nav-links">
                    <a href="#home" class="nav-link hover:text-noceco-mustard transition-colors text-noceco-mustard">Home</a>
                    <a href="#about" class="nav-link hover:text-noceco-mustard transition-colors">About Us</a>
                    <a href="#corporate" class="nav-link hover:text-noceco-mustard transition-colors">Corporate</a>
                    <a href="#news" class="nav-link hover:text-noceco-mustard transition-colors">Updates</a>
                    <a href="#rates" class="nav-link hover:text-noceco-mustard transition-colors">Rates</a>
                    <a href="#care" class="nav-link hover:text-noceco-mustard transition-colors">Customer Care</a>
                </div>

                <div class="flex items-center gap-4">
                    <a href="login.php" class="hidden md:flex items-center bg-gray-900 hover:bg-black text-white px-6 py-2.5 rounded-full font-bold text-sm transition-all shadow-md hover:shadow-lg">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                        Consumer Login
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <header id="home" class="relative h-[85vh] w-full bg-gray-900 overflow-hidden pt-20 scroll-mt-20">
        <?php if(!empty($carouselPics)): ?>
            <?php foreach($carouselPics as $index => $pic): ?>
                <div class="carousel-slide <?php echo $index === 0 ? 'active' : ''; ?> absolute inset-0 w-full h-full">
                    <div class="absolute inset-0 bg-black/60 z-10"></div>
                    <img src="<?php echo htmlspecialchars($pic['image_url']); ?>" class="absolute inset-0 w-full h-full object-cover" alt="Hero Image">
                    <div class="relative z-20 h-full flex flex-col items-center justify-center text-center px-6 max-w-4xl mx-auto">
                        <h1 class="text-5xl md:text-7xl font-black text-white tracking-tighter mb-6"><?php echo htmlspecialchars($pic['title']); ?></h1>
                        <p class="text-lg md:text-2xl text-gray-200 font-medium mb-10"><?php echo htmlspecialchars($pic['content']); ?></p>
                        <div class="flex flex-col sm:flex-row gap-4">
                            <a href="login.php" class="bg-noceco-mustard hover:bg-noceco-mustardHover text-white px-8 py-4 rounded-full font-bold text-lg transition-all shadow-lg hover:shadow-xl">View My Bill Online</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="absolute bottom-10 left-0 right-0 z-30 flex justify-center gap-3">
                <?php foreach($carouselPics as $index => $pic): ?>
                    <button class="carousel-dot w-3 h-3 rounded-full <?php echo $index === 0 ? 'bg-noceco-mustard' : 'bg-white/50 hover:bg-white'; ?> transition-all" onclick="setSlide(<?php echo $index; ?>)"></button>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="carousel-slide active absolute inset-0 w-full h-full">
                <div class="absolute inset-0 bg-black/60 z-10"></div>
                <img src="https://images.unsplash.com/photo-1473341304170-971dccb5ac1e?q=80&w=2070&auto=format&fit=crop" class="absolute inset-0 w-full h-full object-cover" alt="Powerlines">
                <div class="relative z-20 h-full flex flex-col items-center justify-center text-center px-6 max-w-4xl mx-auto">
                    <h1 class="text-5xl md:text-7xl font-black text-white tracking-tighter mb-6">Powering <span class="text-noceco-mustard">Negros</span>.</h1>
                    <p class="text-lg md:text-2xl text-gray-200 font-medium mb-10">Delivering reliable, safe, and sustainable electricity to our communities for a brighter tomorrow.</p>
                    <div class="flex flex-col sm:flex-row gap-4">
                        <a href="login.php" class="bg-noceco-mustard hover:bg-noceco-mustardHover text-white px-8 py-4 rounded-full font-bold text-lg transition-all shadow-lg hover:shadow-xl">View My Bill Online</a>
                        <a href="#about" class="bg-white/10 hover:bg-white/20 backdrop-blur-md border border-white/30 text-white px-8 py-4 rounded-full font-bold text-lg transition-all">Discover NOCECO</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </header>

    <div class="bg-gray-900 border-y border-gray-800 py-3 marquee-container text-white text-sm font-semibold tracking-wide flex">
        <div class="marquee-content flex gap-12 px-12">
            <?php if(empty($marqueeItems)): ?>
                <span class="flex items-center text-gray-400">Welcome to Negros Occidental Electric Cooperative (NOCECO) Official Website.</span>
            <?php else: foreach($marqueeItems as $m): ?>
                <span class="flex items-center <?php echo $m['type'] == 'Interruption' ? 'text-red-400' : ($m['type'] == 'Rate' ? 'text-green-400' : 'text-noceco-mustard'); ?>">
                    <?php if($m['type'] == 'Interruption'): ?>
                        <span class="w-2.5 h-2.5 rounded-full bg-red-500 mr-2 animate-pulse shrink-0"></span>
                    <?php elseif($m['type'] == 'Rate'): ?>
                        <span class="w-2.5 h-2.5 rounded-full bg-green-500 mr-2 shrink-0"></span>
                    <?php else: ?>
                        <svg class="w-4 h-4 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <?php endif; ?>
                    <strong class="uppercase mr-1 tracking-wider"><?php echo htmlspecialchars($m['title']); ?>:</strong> 
                    <span class="text-gray-300 font-medium"><?php echo htmlspecialchars($m['content']); ?></span>
                </span>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <section id="about" class="py-24 bg-noceco-bg scroll-mt-20 section-block">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="flex flex-col lg:flex-row gap-16 items-center">
                <div class="lg:w-1/2">
                    <h4 class="text-noceco-mustard font-bold tracking-widest uppercase text-sm mb-2">About Us</h4>
                    <h2 class="text-4xl md:text-5xl font-black text-gray-900 tracking-tight mb-6">Committed to Service Excellence.</h2>
                    <p class="text-lg text-gray-600 leading-relaxed mb-6">
                        Negros Occidental Electric Cooperative (NOCECO) has been the backbone of rural electrification in the southern part of Negros Occidental. We strive to provide reliable, efficient, and affordable electricity to empower homes, businesses, and communities.
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mt-10">
                        <div class="bg-white p-6 rounded-2xl shadow-apple-sm border border-gray-100 hover:-translate-y-1 transition-transform">
                            <div class="w-10 h-10 bg-yellow-50 text-noceco-mustard rounded-full flex items-center justify-center mb-4"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg></div>
                            <h3 class="font-bold text-gray-900 mb-2">Our Mission</h3>
                            <p class="text-sm text-gray-500">To provide total customer satisfaction through highly reliable power supply at reasonable rates.</p>
                        </div>
                        <div class="bg-white p-6 rounded-2xl shadow-apple-sm border border-gray-100 hover:-translate-y-1 transition-transform">
                            <div class="w-10 h-10 bg-blue-50 text-blue-500 rounded-full flex items-center justify-center mb-4"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg></div>
                            <h3 class="font-bold text-gray-900 mb-2">Our Vision</h3>
                            <p class="text-sm text-gray-500">To be a globally competitive electric cooperative, illuminating and empowering lives.</p>
                        </div>
                    </div>
                </div>
                <div class="lg:w-1/2 w-full">
                    <img src="https://taraenergy.com/wp-content/uploads/2022/11/history-of-electricity-article-image-of-electric-powerlines.jpeg" class="w-full h-[500px] object-cover rounded-[32px] shadow-2xl border-8 border-white" alt="NOCECO Building">
                </div>
            </div>
        </div>
    </section>

    <section id="corporate" class="py-24 bg-gray-900 text-white scroll-mt-20 section-block">
        <div class="max-w-7xl mx-auto px-6 lg:px-8 text-center mb-16">
            <h4 class="text-noceco-mustard font-bold tracking-widest uppercase text-sm mb-2">Corporate</h4>
            <h2 class="text-4xl md:text-5xl font-black tracking-tight">A Sustainable Future.</h2>
            <p class="text-gray-400 mt-4 max-w-2xl mx-auto text-lg">Investing in grid modernization, renewable energy integration, and community development across Southern Negros.</p>
        </div>
        <div class="max-w-7xl mx-auto px-6 lg:px-8 grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="bg-white/10 backdrop-blur-md p-8 rounded-3xl border border-white/20 hover:bg-white/20 transition-colors">
                <h3 class="text-xl font-bold mb-3 text-noceco-mustard">Franchise Area</h3>
                <p class="text-gray-300 text-sm leading-relaxed">NOCECO powers major economic hubs including Kabankalan, Himamaylan, Binalbagan, and Hinigaran. We manage over 1,500 kilometers of distribution lines.</p>
            </div>
            <div class="bg-white/10 backdrop-blur-md p-8 rounded-3xl border border-white/20 hover:bg-white/20 transition-colors">
                <h3 class="text-xl font-bold mb-3 text-noceco-mustard">Procurement & Bidding</h3>
                <p class="text-gray-300 text-sm leading-relaxed">We uphold transparency in all our operations. Vendors and suppliers can access our latest Invitations to Bid (ITB) and terms of reference.</p>
            </div>
            <div class="bg-white/10 backdrop-blur-md p-8 rounded-3xl border border-white/20 hover:bg-white/20 transition-colors">
                <h3 class="text-xl font-bold mb-3 text-noceco-mustard">System Loss Reduction</h3>
                <p class="text-gray-300 text-sm leading-relaxed">Through advanced line clearing and smart metering, NOCECO consistently keeps system loss rates below the ERC mandate.</p>
            </div>
        </div>
    </section>

    <?php if(!empty($newsItems)): ?>
    <section id="news" class="py-24 bg-noceco-bg scroll-mt-20 section-block">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <div class="text-center mb-16">
                <h4 class="text-noceco-mustard font-bold tracking-widest uppercase text-sm mb-2">News & Media</h4>
                <h2 class="text-4xl md:text-5xl font-black text-gray-900 tracking-tight">Latest Updates.</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <?php foreach($newsItems as $news): ?>
                    <div class="bg-white rounded-3xl border border-gray-100 shadow-apple-sm hover:-translate-y-2 transition-transform duration-300 cursor-pointer overflow-hidden flex flex-col group"
                         data-title="<?php echo htmlspecialchars($news['title']); ?>"
                         data-date="<?php echo date('F d, Y', strtotime($news['created_at'])); ?>"
                         data-type="<?php echo htmlspecialchars($news['type']); ?>"
                         data-content="<?php echo htmlspecialchars($news['content']); ?>"
                         data-img="<?php echo htmlspecialchars($news['image_url'] ?? ''); ?>"
                         onclick="openArticleModal(this)">
                         
                        <?php if(!empty($news['image_url'])): ?>
                            <img src="<?php echo htmlspecialchars($news['image_url']); ?>" alt="News Image" class="w-full h-48 object-cover">
                        <?php endif; ?>

                        <div class="p-8 flex-1 flex flex-col">
                            <span class="text-[10px] font-black text-blue-600 bg-blue-50 px-3 py-1 rounded-full uppercase tracking-widest mb-4 inline-block self-start"><?php echo htmlspecialchars($news['type']); ?></span>
                            <h3 class="text-xl font-bold text-gray-900 mb-3 leading-tight group-hover:text-noceco-mustard transition-colors"><?php echo htmlspecialchars($news['title']); ?></h3>
                            <p class="text-sm text-gray-500 leading-relaxed mb-6 line-clamp-3"><?php echo htmlspecialchars($news['content']); ?></p>
                            <div class="text-xs font-bold text-gray-400 flex justify-between items-center border-t border-gray-100 pt-4 mt-auto">
                                <span><?php echo date('M d, Y', strtotime($news['created_at'])); ?></span>
                                <span class="text-noceco-mustard group-hover:underline">Read Full →</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section id="rates" class="py-24 bg-white scroll-mt-20 section-block">
        <div class="max-w-4xl mx-auto px-6 lg:px-8 text-center">
            <h4 class="text-noceco-mustard font-bold tracking-widest uppercase text-sm mb-2">Billing Rates</h4>
            <h2 class="text-4xl md:text-5xl font-black text-gray-900 tracking-tight mb-6">Transparent Pricing.</h2>
            <p class="text-lg text-gray-600 mb-10">Current unbundled electricity rates updated by the cooperative.</p>
            
            <div class="flex justify-center gap-2 mb-8 bg-gray-100 p-2 rounded-xl inline-flex">
                <button onclick="switchTab('res')" id="tab-res" class="rate-tab bg-white shadow-sm text-gray-900 px-6 py-2 rounded-lg font-bold text-sm transition-all">Residential</button>
                <button onclick="switchTab('com')" id="tab-com" class="rate-tab text-gray-500 hover:text-gray-900 px-6 py-2 rounded-lg font-bold text-sm transition-all">Commercial</button>
            </div>

            <div id="content-res" class="rate-content text-left bg-gray-50 rounded-2xl p-8 border border-gray-200 shadow-apple-sm">
                <div class="flex justify-between items-center mb-6 pb-6 border-b border-gray-200">
                    <h3 class="text-2xl font-black text-gray-900">Residential</h3>
                    <div class="text-right">
                        <p class="text-[10px] uppercase font-bold text-gray-400 tracking-widest">Effective Rate (Live DB)</p>
                        <p class="text-3xl font-black text-noceco-mustard">₱<?php echo $displayRate; ?><span class="text-sm text-gray-500">/kWh</span></p>
                    </div>
                </div>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between"><span class="text-gray-600">Generation Charge</span><span class="font-bold">₱<?php echo $genCharge; ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-600">Transmission Charge</span><span class="font-bold">₱<?php echo $transCharge; ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-600">System Loss</span><span class="font-bold">₱<?php echo $sysLossCharge; ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-600">Distribution Charge</span><span class="font-bold">₱<?php echo $distCharge; ?></span></div>
                    <div class="flex justify-between border-t border-gray-200 pt-3"><span class="text-gray-400 italic">Includes VAT & Subsidies</span></div>
                </div>
            </div>

            <div id="content-com" class="rate-content hidden text-left bg-gray-50 rounded-2xl p-8 border border-gray-200 shadow-apple-sm">
                <div class="flex justify-between items-center mb-6 pb-6 border-b border-gray-200">
                    <h3 class="text-2xl font-black text-gray-900">Commercial</h3>
                    <div class="text-right">
                        <p class="text-[10px] uppercase font-bold text-gray-400 tracking-widest">Base Rate Estimate</p>
                        <p class="text-3xl font-black text-noceco-mustard">₱<?php echo number_format((float)$displayRate - 0.60, 4); ?><span class="text-sm text-gray-500">/kWh</span></p>
                    </div>
                </div>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between"><span class="text-gray-600">Generation Charge</span><span class="font-bold">₱<?php echo $genCharge; ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-600">Transmission Demand</span><span class="font-bold">₱205.50/kW</span></div>
                    <div class="flex justify-between"><span class="text-gray-600">Distribution Demand</span><span class="font-bold">₱195.00/kW</span></div>
                    <div class="flex justify-between border-t border-gray-200 pt-3"><span class="text-gray-400 italic">Excludes Demand Charges</span></div>
                </div>
            </div>
        </div>
    </section>

    <section id="care" class="py-24 bg-noceco-bg scroll-mt-20 section-block">
        <div class="max-w-7xl mx-auto px-6 lg:px-8 grid grid-cols-1 lg:grid-cols-2 gap-16">
            
            <div id="safety">
                <h4 class="text-noceco-mustard font-bold tracking-widest uppercase text-sm mb-2">Customer Care & Safety</h4>
                <h2 class="text-4xl font-black text-gray-900 tracking-tight mb-6">We are here to help.</h2>
                <p class="text-gray-600 mb-8">NOCECO values your safety and satisfaction. Below are common questions and guidelines to keep your home powered safely.</p>
                
                <div class="bg-red-50 border-l-4 border-red-500 p-6 rounded-r-xl mb-8">
                    <h3 class="font-bold text-red-700 mb-2 flex items-center"><svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg> Emergency Hotline</h3>
                    <p class="text-sm text-red-600">For sparked lines, fallen posts, or severe outages, immediately contact our 24/7 technical team at <strong>(034) 471-2229</strong>.</p>
                </div>
            </div>

            <div class="space-y-4">
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                    <button class="w-full text-left px-6 py-4 font-bold text-gray-900 flex justify-between items-center focus:outline-none" onclick="toggleAccordion('faq1', this)">
                        How do I apply for a new connection?
                        <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div id="faq1" class="accordion-content px-6 text-sm text-gray-600">
                        You need to visit the nearest NOCECO area office and bring your Electrical Plan, valid ID, Barangay Clearance, and Proof of Ownership.
                    </div>
                </div>

                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                    <button class="w-full text-left px-6 py-4 font-bold text-gray-900 flex justify-between items-center focus:outline-none" onclick="toggleAccordion('faq2', this)">
                        What should I do during a typhoon?
                        <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div id="faq2" class="accordion-content px-6 text-sm text-gray-600">
                        Always unplug all appliances and turn off your main breaker if floodwaters threaten your home. Do not touch wet electrical outlets or fallen power lines.
                    </div>
                </div>

                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                    <button class="w-full text-left px-6 py-4 font-bold text-gray-900 flex justify-between items-center focus:outline-none" onclick="toggleAccordion('faq3', this)">
                        Where can I pay my NOCECO Bill?
                        <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div id="faq3" class="accordion-content px-6 text-sm text-gray-600">
                        You can pay at any NOCECO Cashier, or register for our <a href="login.php" class="text-noceco-mustard font-bold hover:underline">Online Consumer Portal</a> to pay seamlessly via GCash and QR Ph.
                    </div>
                </div>
            </div>

        </div>
    </section>

    <footer id="contact" class="bg-gray-900 text-gray-400 py-16 border-t border-gray-800 scroll-mt-20 section-block">
        <div class="max-w-7xl mx-auto px-6 lg:px-8 grid grid-cols-1 md:grid-cols-3 gap-12 border-b border-gray-800 pb-12 mb-8">
            <div>
                <div class="flex items-center gap-2 mb-6">
                    <div class="w-8 h-8 rounded-full bg-noceco-mustard flex items-center justify-center">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </div>
                    <span class="font-black text-xl text-white tracking-tighter">NOCECO</span>
                </div>
                <p class="text-sm leading-relaxed mb-6">Providing reliable and sustainable power to Southern Negros Occidental. Illuminating lives, empowering communities.</p>
            </div>
            
            <div>
                <h4 class="text-white font-bold mb-6 tracking-widest uppercase text-xs">Main Office</h4>
                <ul class="space-y-4 text-sm">
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-gray-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        <span>Sitio Naga, Brgy. Binicuil<br>Kabankalan City, Negros Occidental 6111</span>
                    </li>
                    <li class="flex items-center gap-3">
                        <svg class="w-5 h-5 text-gray-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                        <span>customercare@noceco.ph</span>
                    </li>
                    <li class="flex items-center gap-3">
                        <svg class="w-5 h-5 text-gray-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                        <span>(034) 471-2229</span>
                    </li>
                </ul>
            </div>

            <div>
                <h4 class="text-white font-bold mb-6 tracking-widest uppercase text-xs">Quick Links</h4>
                <ul class="space-y-3 text-sm flex flex-col">
                    <a href="login.php" class="hover:text-white transition-colors">Consumer Portal Login</a>
                    <a href="administrator.php" class="hover:text-white transition-colors">Employee Portal</a>
                </ul>
            </div>
        </div>
        <div class="text-center text-xs">
            <p>&copy; <?php echo date('Y'); ?> Negros Occidental Electric Cooperative (NOCECO). All Rights Reserved.</p>
        </div>
    </footer>

    <div id="articleModal" class="fixed inset-0 z-[100] bg-gray-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
        <div class="bg-white rounded-[24px] max-w-2xl w-full max-h-[85vh] overflow-hidden flex flex-col transform scale-95 transition-transform duration-300 shadow-2xl" id="articleModalContent">
            
            <div class="p-6 border-b border-gray-100 flex justify-between items-start bg-gray-50/50">
                <div>
                    <span id="modalType" class="text-[10px] font-black text-blue-600 bg-blue-50 px-3 py-1 rounded-full uppercase tracking-widest mb-3 inline-block">Type</span>
                    <h2 id="modalTitle" class="text-2xl font-black text-gray-900 leading-tight">Title</h2>
                    <p id="modalDate" class="text-xs font-bold text-gray-400 mt-2">Date</p>
                </div>
                <button onclick="closeArticleModal()" class="bg-white border border-gray-200 hover:bg-gray-100 text-gray-600 p-2 rounded-full transition-colors shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <div class="p-8 overflow-y-auto custom-scrollbar flex-1 text-gray-700">
                <img id="modalImage" src="" class="w-full h-64 object-cover rounded-xl mb-8 hidden shadow-md border border-gray-100">
                <p id="modalText" class="leading-relaxed whitespace-pre-wrap text-base"></p>
            </div>
        </div>
    </div>

    <script>
        // Navbar Scroll Effect & Scroll Spy
        const nav = document.getElementById('navbar');
        const sections = document.querySelectorAll('.section-block');
        const navLinks = document.querySelectorAll('.nav-link');

        window.addEventListener('scroll', () => {
            if (window.scrollY > 20) nav.classList.add('shadow-sm');
            else nav.classList.remove('shadow-sm');

            let current = '';
            sections.forEach(section => {
                const sectionTop = section.offsetTop;
                if (scrollY >= sectionTop - 100) {
                    current = section.getAttribute('id');
                }
            });

            navLinks.forEach(link => {
                link.classList.remove('text-noceco-mustard');
                if (link.getAttribute('href').includes(current)) {
                    link.classList.add('text-noceco-mustard');
                }
            });
        });

        // Carousel Logic
        const slides = document.querySelectorAll('.carousel-slide');
        const dots = document.querySelectorAll('.carousel-dot');
        let currentSlide = 0;

        if(slides.length > 0) {
            function setSlide(index) {
                slides[currentSlide].classList.remove('active');
                if(dots.length > 0) {
                    dots[currentSlide].classList.remove('bg-noceco-mustard');
                    dots[currentSlide].classList.add('bg-white/50');
                }
                currentSlide = index;
                slides[currentSlide].classList.add('active');
                if(dots.length > 0) {
                    dots[currentSlide].classList.add('bg-noceco-mustard');
                    dots[currentSlide].classList.remove('bg-white/50');
                }
            }
            setInterval(() => { setSlide((currentSlide + 1) % slides.length); }, 6000);
        }

        // Rates Tab Switcher
        function switchTab(tabId) {
            document.querySelectorAll('.rate-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.rate-tab').forEach(el => {
                el.classList.remove('bg-white', 'shadow-sm', 'text-gray-900');
                el.classList.add('text-gray-500');
            });
            document.getElementById('content-' + tabId).classList.remove('hidden');
            document.getElementById('tab-' + tabId).classList.add('bg-white', 'shadow-sm', 'text-gray-900');
            document.getElementById('tab-' + tabId).classList.remove('text-gray-500');
        }

        // Accordion FAQ
        function toggleAccordion(id, btn) {
            const content = document.getElementById(id);
            const icon = btn.querySelector('svg');
            
            if (content.classList.contains('open')) {
                content.classList.remove('open');
                icon.style.transform = 'rotate(0deg)';
            } else {
                document.querySelectorAll('.accordion-content').forEach(el => el.classList.remove('open'));
                document.querySelectorAll('.accordion-content').forEach(el => el.previousElementSibling.querySelector('svg').style.transform = 'rotate(0deg)');
                content.classList.add('open');
                icon.style.transform = 'rotate(180deg)';
            }
        }

        // Modal Logic
        function openArticleModal(el) {
            document.getElementById('modalTitle').textContent = el.getAttribute('data-title');
            document.getElementById('modalDate').textContent = el.getAttribute('data-date');
            document.getElementById('modalType').textContent = el.getAttribute('data-type');
            document.getElementById('modalText').textContent = el.getAttribute('data-content');

            const img = el.getAttribute('data-img');
            const imgEl = document.getElementById('modalImage');
            if (img) {
                imgEl.src = img;
                imgEl.classList.remove('hidden');
            } else {
                imgEl.classList.add('hidden');
            }

            const modal = document.getElementById('articleModal');
            const modalContent = document.getElementById('articleModalContent');
            
            document.body.classList.add('modal-open');
            
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                modalContent.classList.remove('scale-95');
            }, 10);
        }

        function closeArticleModal() {
            const modal = document.getElementById('articleModal');
            const modalContent = document.getElementById('articleModalContent');
            
            document.body.classList.remove('modal-open');
            
            modal.classList.add('opacity-0');
            modalContent.classList.add('scale-95');
            
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        document.getElementById('articleModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeArticleModal();
            }
        });
    </script>
</body>
</html>