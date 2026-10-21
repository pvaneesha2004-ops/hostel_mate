/**
 * HOSTEL MATE - Official Website Interactive Logic
 */

document.addEventListener('DOMContentLoaded', function () {
  // 1. Sticky Header
  const header = document.querySelector('.site-header');
  const backToTopBtn = document.querySelector('.back-to-top');

  window.addEventListener('scroll', function () {
    if (window.scrollY > 40) {
      header.classList.add('scrolled');
    } else {
      header.classList.remove('scrolled');
    }

    if (window.scrollY > 400) {
      if (backToTopBtn) backToTopBtn.classList.add('show');
    } else {
      if (backToTopBtn) backToTopBtn.classList.remove('show');
    }
  });

  // 2. Mobile Menu Toggle
  const mobileToggle = document.querySelector('.mobile-toggle-btn');
  const navLinks = document.querySelector('.nav-links');

  if (mobileToggle && navLinks) {
    mobileToggle.addEventListener('click', function () {
      navLinks.classList.toggle('mobile-open');
      const icon = mobileToggle.querySelector('i');
      if (icon) {
        if (navLinks.classList.contains('mobile-open')) {
          icon.classList.remove('fa-bars');
          icon.classList.add('fa-xmark');
        } else {
          icon.classList.remove('fa-xmark');
          icon.classList.add('fa-bars');
        }
      }
    });

    // Close menu when clicking link
    document.querySelectorAll('.nav-link').forEach(link => {
      link.addEventListener('click', () => {
        navLinks.classList.remove('mobile-open');
        const icon = mobileToggle.querySelector('i');
        if (icon) {
          icon.classList.remove('fa-xmark');
          icon.classList.add('fa-bars');
        }
      });
    });
  }

  // 3. Hero Carousel Slider
  const slides = document.querySelectorAll('.slide-item');
  const dots = document.querySelectorAll('.slider-dot');
  const prevBtn = document.querySelector('.slider-prev');
  const nextBtn = document.querySelector('.slider-next');
  let currentSlide = 0;
  let slideInterval;
  const slideDuration = 6000;

  function showSlide(index) {
    if (!slides.length) return;
    slides.forEach(s => s.classList.remove('active'));
    dots.forEach(d => d.classList.remove('active'));

    currentSlide = (index + slides.length) % slides.length;
    slides[currentSlide].classList.add('active');
    if (dots[currentSlide]) {
      dots[currentSlide].classList.add('active');
    }
  }

  function nextSlide() {
    showSlide(currentSlide + 1);
  }

  function prevSlide() {
    showSlide(currentSlide - 1);
  }

  function startAutoSlide() {
    stopAutoSlide();
    slideInterval = setInterval(nextSlide, slideDuration);
  }

  function stopAutoSlide() {
    if (slideInterval) clearInterval(slideInterval);
  }

  if (slides.length > 0) {
    if (nextBtn) {
      nextBtn.addEventListener('click', () => {
        nextSlide();
        startAutoSlide();
      });
    }

    if (prevBtn) {
      prevBtn.addEventListener('click', () => {
        prevSlide();
        startAutoSlide();
      });
    }

    dots.forEach((dot, idx) => {
      dot.addEventListener('click', () => {
        showSlide(idx);
        startAutoSlide();
      });
    });

    // Pause on hover
    const heroTrack = document.querySelector('.hero-slider-track');
    if (heroTrack) {
      heroTrack.addEventListener('mouseenter', stopAutoSlide);
      heroTrack.addEventListener('mouseleave', startAutoSlide);
    }

    startAutoSlide();
  }

  // 4. Counter Animation on Scroll
  const statNumbers = document.querySelectorAll('.stat-num');
  let animatedStats = false;

  function runCounters() {
    if (animatedStats || !statNumbers.length) return;
    const statsBar = document.querySelector('.stats-floating-bar');
    if (!statsBar) return;

    const rect = statsBar.getBoundingClientRect();
    if (rect.top <= window.innerHeight * 0.9) {
      animatedStats = true;
      statNumbers.forEach(counter => {
        const target = +counter.getAttribute('data-target') || 0;
        const speed = 40;
        const step = Math.ceil(target / speed);
        let current = 0;

        const timer = setInterval(() => {
          current += step;
          if (current >= target) {
            counter.innerText = target.toLocaleString() + (counter.getAttribute('data-suffix') || '');
            clearInterval(timer);
          } else {
            counter.innerText = current.toLocaleString() + (counter.getAttribute('data-suffix') || '');
          }
        }, 30);
      });
    }
  }

  window.addEventListener('scroll', runCounters);
  runCounters(); // run initially if in view

  // 5. Dining Mess Tabs
  const diningTabs = document.querySelectorAll('.dining-tab-btn');
  const diningPanels = document.querySelectorAll('.dining-content-panel');

  diningTabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const targetId = tab.getAttribute('data-meal');
      diningTabs.forEach(t => t.classList.remove('active'));
      diningPanels.forEach(p => p.classList.remove('active'));

      tab.classList.add('active');
      const activePanel = document.getElementById('meal-' + targetId);
      if (activePanel) {
        activePanel.classList.add('active');
      }
    });
  });

  // 6. FAQ Accordion
  const faqItems = document.querySelectorAll('.faq-item');
  faqItems.forEach(item => {
    const question = item.querySelector('.faq-question');
    if (question) {
      question.addEventListener('click', () => {
        const isActive = item.classList.contains('active');
        faqItems.forEach(f => f.classList.remove('active'));
        if (!isActive) {
          item.classList.add('active');
        }
      });
    }
  });

  // 7. Interactive Inquiry Form
  const contactForm = document.getElementById('hostelInquiryForm');
  if (contactForm) {
    contactForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const submitBtn = contactForm.querySelector('button[type="submit"]');
      const originalText = submitBtn.innerHTML;

      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Submitting...';

      setTimeout(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-solid fa-check"></i> Inquiry Received!';
        submitBtn.style.backgroundColor = '#10b981';

        alert('Thank you! Your inquiry has been submitted to the Hostel Mate Admissions & Warden Desk. Our team will contact you within 24 hours.');
        contactForm.reset();

        setTimeout(() => {
          submitBtn.innerHTML = originalText;
          submitBtn.style.backgroundColor = '';
        }, 4000);
      }, 900);
    });
  }
});
