<!doctype html>
<html lang="zh-CN">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no" />
  <title>{{$title}}</title>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/umi.js"></script>
  
  <!-- Fluid Ripple Effect Styles -->
  <style>
    /* Canvas container for ripple effect */
    #ripple-canvas {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      pointer-events: none;
      z-index: 0;
    }

    /* Ensure app content is above canvas */
    #app {
      position: relative;
      z-index: 1;
    }
  </style>

</head>

<body>

  <!-- Fluid Ripple Effect Canvas -->
  <canvas id="ripple-canvas"></canvas>

  <script>
    window.routerBase = "/";
    window.settings = {
      title: '{{$title}}',
      assets_path: '/theme/{{$theme}}/assets',
      theme: {
        color: '{{ $theme_config['theme_color'] ?? "default" }}',
      },
      version: '{{$version}}',
      background_url: '{{$theme_config['background_url']}}',
      description: '{{$description}}',
      i18n: [
        'zh-CN',
        'en-US',
        'ja-JP',
        'vi-VN',
        'ko-KR',
        'zh-TW',
        'fa-IR'
      ],
      logo: '{{$logo}}'
    }
  </script>
  <div id="app"></div>
  {!! $theme_config['custom_html'] !!}
  <script>
    (function() {
      function insertLoginLogo() {
        // Fix persistence: Allow query params like #/login?redirect=...
        if (!window.location.hash.startsWith('#/login')) return;
        
        // Prevent duplicate injection
        if (document.getElementById('custom-login-logo')) return;

        // Naive UI Card selectors
        const card = document.querySelector('.n-card.n-card--bordered.mx-auto.max-w-md');
        const cardContent = document.querySelector('.n-card__content');

        if (card && cardContent) {
          const logo = document.createElement('img');
          logo.src = '/home_logo.jpeg';
          logo.id = 'custom-login-logo';
          logo.style.display = 'block';
          // Style to fit nicely inside the card top
          // Reduced top margin and negative bottom margin to bring it closer to title
          logo.style.margin = '10px auto -15px auto';
          logo.style.maxWidth = '120px';
          logo.style.height = 'auto';
          logo.style.objectFit = 'contain';
          logo.style.position = 'relative'; // Ensure z-index works if needed
          logo.style.zIndex = '1';
          
          // Insert inside the card, before the content area
          card.insertBefore(logo, cardContent);
        }
      }

      function insertSidebarLogo() {
        // Only run if NOT on login page
        if (window.location.hash.startsWith('#/login')) return;

        // Prevent duplicate injection
        if (document.getElementById('custom-sidebar-logo')) return;

        // Try multiple selectors
        let titleEl = document.querySelector('.title-text') || 
                      document.querySelector('h2');

        // Fallback: finding the sidebar content container if title specific class missing
        if (!titleEl) {
            const sidebar = document.querySelector('.n-layout-sider');
            if (sidebar) {
                 const scrollbar = sidebar.querySelector('.n-scrollbar');
                 if (scrollbar && scrollbar.firstChild) {
                     // Check if first child seems to be the title (text node or element with text)
                     // Using the scrollbar's first child as the reference to insert BEFORE
                     titleEl = scrollbar.firstChild;
                 }
            }
        }

        if (titleEl) {
            console.log('Xboard Theme: Found sidebar target, injecting logo.');
            const logo = document.createElement('img');
            logo.src = '/home_logo.jpeg';
            logo.id = 'custom-sidebar-logo';
            logo.style.height = '40px'; 
            logo.style.width = 'auto';
            logo.style.marginRight = '12px';
            logo.style.marginTop = '0px';
            logo.style.borderRadius = '4px';
            logo.style.objectFit = 'contain';
            logo.style.display = 'inline-block'; // Ensure it's not hidden
            
            // Insert before the title/reference element
            if (titleEl.parentNode) {
                titleEl.parentNode.insertBefore(logo, titleEl);
            }
        }
      }

      // Use a combination of MutationObserver and setInterval for robustness
      const observer = new MutationObserver((mutations) => {
        insertLoginLogo();
        insertSidebarLogo();
      });

      observer.observe(document.body, {
        childList: true,
        subtree: true
      });

      // Periodic check in case MutationObserver misses it (common in some frameworks)
      setInterval(() => {
          insertLoginLogo();
          insertSidebarLogo();
      }, 1000);

      // Initial try
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            insertLoginLogo();
            insertSidebarLogo();
        });
      } else {
        insertLoginLogo();
        insertSidebarLogo();
      }
    })();


    // Fluid Ripple Effect Script
    (function() {
      const canvas = document.getElementById('ripple-canvas');
      
      if (!canvas) return;

      const ctx = canvas.getContext('2d');
      let ripples = [];
      let animationId;
      
      // Set canvas size
      function resizeCanvas() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
      }
      
      resizeCanvas();
      window.addEventListener('resize', resizeCanvas);
      
      // Ripple class
      class Ripple {
        constructor(x, y) {
          this.x = x;
          this.y = y;
          this.radius = 0;
          this.maxRadius = 150;
          this.speed = 3;
          this.opacity = 0.6;
          this.fadeSpeed = 0.015;
          
          // Random gradient colors
          const colors = [
            ['rgba(102, 126, 234', 'rgba(118, 75, 162'],
            ['rgba(240, 147, 251', 'rgba(245, 87, 108'],
            ['rgba(79, 172, 254', 'rgba(0, 242, 254'],
            ['rgba(67, 233, 123', 'rgba(56, 249, 215'],
            ['rgba(250, 112, 154', 'rgba(254, 225, 64'],
          ];
          
          const colorPair = colors[Math.floor(Math.random() * colors.length)];
          this.color1 = colorPair[0];
          this.color2 = colorPair[1];
        }
        
        update() {
          this.radius += this.speed;
          this.opacity -= this.fadeSpeed;
          
          if (this.radius > this.maxRadius * 0.5) {
            this.speed *= 0.95;
          }
        }
        
        draw(ctx) {
          if (this.opacity <= 0) return;
          
          const gradient = ctx.createRadialGradient(
            this.x, this.y, 0,
            this.x, this.y, this.radius
          );
          
          gradient.addColorStop(0, `${this.color1}, ${this.opacity})`);
          gradient.addColorStop(0.5, `${this.color2}, ${this.opacity * 0.5})`);
          gradient.addColorStop(1, `${this.color1}, 0)`);
          
          ctx.fillStyle = gradient;
          ctx.beginPath();
          ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2);
          ctx.fill();
        }
        
        isAlive() {
          return this.opacity > 0 && this.radius < this.maxRadius;
        }
      }
      
      // Show ripple ONLY on login page
      function updateRippleVisibility() {
        const hash = window.location.hash;
        if (hash.includes('login')) {
          canvas.style.display = 'block';
          if (!animationId) animate();
        } else {
          canvas.style.display = 'none';
          ripples = [];
          if (animationId) {
            cancelAnimationFrame(animationId);
            animationId = null;
          }
        }
      }
      
      // Initial check
      updateRippleVisibility();
      
      // Listen to hash changes
      window.addEventListener('hashchange', updateRippleVisibility);
      
      // Animation loop
      function animate() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        
        // Update and draw ripples
        ripples = ripples.filter(ripple => {
          ripple.update();
          ripple.draw(ctx);
          return ripple.isAlive();
        });
        
        animationId = requestAnimationFrame(animate);
      }
      
      // Mouse move handler
      let lastRippleTime = 0;
      const rippleInterval = 50; // ms between ripples
      
      document.addEventListener('mousemove', (e) => {
        if (!canvas.style.display || canvas.style.display === 'none') return;
        
        const currentTime = Date.now();
        if (currentTime - lastRippleTime > rippleInterval) {
          ripples.push(new Ripple(e.clientX, e.clientY));
          lastRippleTime = currentTime;
        }
      });
      
      // Click to create larger ripple
      document.addEventListener('click', (e) => {
        if (!canvas.style.display || canvas.style.display === 'none') return;
        
        const ripple = new Ripple(e.clientX, e.clientY);
        ripple.maxRadius = 200;
        ripple.opacity = 0.8;
        ripples.push(ripple);
      });
    })();
  </script>
</body>

</html>