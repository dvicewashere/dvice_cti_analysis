/**
 * Table Carousel Controller
 * Cyber-themed 3D carousel for data tables with multiple navigation methods
 */

class TableCarousel {
    constructor() {
        this.carousel = document.getElementById('tableCarousel');
        this.titleElement = document.getElementById('activeTableTitle');
        this.stepsContainer = document.getElementById('tableSteps');
        this.slides = Array.from(this.carousel.querySelectorAll('.table-slide'));
        this.stepDots = Array.from(this.stepsContainer.querySelectorAll('.step-dot'));
        this.prevBtn = document.querySelector('.carousel-nav.prev');
        this.nextBtn = document.querySelector('.carousel-nav.next');
        
        this.activeIndex = 0;
        this.totalSlides = this.slides.length;
        this.isTransitioning = false;
        
        // Wheel navigation throttling
        this.lastWheelTime = 0;
        this.wheelThrottle = 600; // ms between transitions
        
        // Swipe detection
        this.touchStartX = 0;
        this.touchStartY = 0;
        this.touchEndX = 0;
        this.touchEndY = 0;
        this.swipeThreshold = 50;
        this.isScrolling = false;
        
        this.init();
    }
    
    init() {
        this.updateSlides();
        this.setupEventListeners();
    }
    
    setupEventListeners() {
        // Arrow buttons
        if (this.prevBtn) {
            this.prevBtn.addEventListener('click', () => this.goToPrev());
        }
        if (this.nextBtn) {
            this.nextBtn.addEventListener('click', () => this.goToNext());
        }
        
        // Step dots
        this.stepDots.forEach((dot, index) => {
            dot.addEventListener('click', () => this.goToIndex(index));
        });
        
        // Click on preview slides
        this.slides.forEach((slide, index) => {
            slide.addEventListener('click', (e) => {
                if (slide.classList.contains('prev')) {
                    this.goToPrev();
                } else if (slide.classList.contains('next')) {
                    this.goToNext();
                }
            });
        });
        
        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                this.goToPrev();
            } else if (e.key === 'ArrowRight') {
                e.preventDefault();
                this.goToNext();
            }
        });
        
        // Wheel navigation (with throttling and scroll detection)
        this.carousel.addEventListener('wheel', (e) => {
            this.handleWheel(e);
        }, { passive: false });
        
        // Touch/swipe detection
        this.carousel.addEventListener('touchstart', (e) => {
            this.handleTouchStart(e);
        }, { passive: true });
        
        this.carousel.addEventListener('touchmove', (e) => {
            this.handleTouchMove(e);
        }, { passive: true });
        
        this.carousel.addEventListener('touchend', (e) => {
            this.handleTouchEnd(e);
        }, { passive: true });
        
        // Pointer events for trackpad gestures
        this.carousel.addEventListener('pointerdown', (e) => {
            this.handlePointerDown(e);
        });
        
        this.carousel.addEventListener('pointermove', (e) => {
            this.handlePointerMove(e);
        });
        
        this.carousel.addEventListener('pointerup', (e) => {
            this.handlePointerUp(e);
        });
    }
    
    handleWheel(e) {
        // Check if user is scrolling inside active table content
        const activeSlide = this.slides[this.activeIndex];
        const activeTable = activeSlide.querySelector('canvas, .analiz-yorumu, table');
        
        if (activeTable) {
            const rect = activeTable.getBoundingClientRect();
            const isOverTable = e.clientX >= rect.left && e.clientX <= rect.right &&
                               e.clientY >= rect.top && e.clientY <= rect.bottom;
            
            // Check if table is scrollable
            const isScrollable = activeTable.scrollHeight > activeTable.clientHeight ||
                                activeTable.scrollWidth > activeTable.clientWidth;
            
            if (isOverTable && isScrollable) {
                // User is scrolling inside table, don't navigate
                return;
            }
        }
        
        // Throttle wheel events
        const now = Date.now();
        if (now - this.lastWheelTime < this.wheelThrottle) {
            e.preventDefault();
            return;
        }
        
        e.preventDefault();
        this.lastWheelTime = now;
        
        if (e.deltaY > 0) {
            // Scroll down -> next
            this.goToNext();
        } else if (e.deltaY < 0) {
            // Scroll up -> prev
            this.goToPrev();
        }
    }
    
    handleTouchStart(e) {
        this.touchStartX = e.touches[0].clientX;
        this.touchStartY = e.touches[0].clientY;
        this.isScrolling = false;
    }
    
    handleTouchMove(e) {
        if (!this.touchStartX || !this.touchStartY) return;
        
        this.touchEndX = e.touches[0].clientX;
        this.touchEndY = e.touches[0].clientY;
        
        const deltaX = Math.abs(this.touchEndX - this.touchStartX);
        const deltaY = Math.abs(this.touchEndY - this.touchStartY);
        
        // Detect if user is scrolling vertically (inside table)
        if (deltaY > deltaX) {
            this.isScrolling = true;
        }
    }
    
    handleTouchEnd(e) {
        if (!this.touchStartX || !this.touchStartY || this.isScrolling) {
            this.touchStartX = 0;
            this.touchStartY = 0;
            return;
        }
        
        const deltaX = this.touchEndX - this.touchStartX;
        
        if (Math.abs(deltaX) > this.swipeThreshold) {
            if (deltaX > 0) {
                // Swipe right -> prev
                this.goToPrev();
            } else {
                // Swipe left -> next
                this.goToNext();
            }
        }
        
        this.touchStartX = 0;
        this.touchStartY = 0;
    }
    
    handlePointerDown(e) {
        this.touchStartX = e.clientX;
        this.touchStartY = e.clientY;
        this.isScrolling = false;
    }
    
    handlePointerMove(e) {
        if (!this.touchStartX || !this.touchStartY) return;
        
        this.touchEndX = e.clientX;
        this.touchEndY = e.clientY;
        
        const deltaX = Math.abs(this.touchEndX - this.touchStartX);
        const deltaY = Math.abs(this.touchEndY - this.touchStartY);
        
        if (deltaY > deltaX) {
            this.isScrolling = true;
        }
    }
    
    handlePointerUp(e) {
        if (!this.touchStartX || !this.touchStartY || this.isScrolling) {
            this.touchStartX = 0;
            this.touchStartY = 0;
            return;
        }
        
        const deltaX = this.touchEndX - this.touchStartX;
        
        if (Math.abs(deltaX) > this.swipeThreshold) {
            if (deltaX > 0) {
                this.goToPrev();
            } else {
                this.goToNext();
            }
        }
        
        this.touchStartX = 0;
        this.touchStartY = 0;
    }
    
    goToIndex(index) {
        if (this.isTransitioning || index === this.activeIndex) return;
        
        this.isTransitioning = true;
        this.activeIndex = index;
        this.updateSlides();
        
        setTimeout(() => {
            this.isTransitioning = false;
        }, 500);
    }
    
    goToNext() {
        if (this.isTransitioning) return;
        const nextIndex = (this.activeIndex + 1) % this.totalSlides;
        this.goToIndex(nextIndex);
    }
    
    goToPrev() {
        if (this.isTransitioning) return;
        const prevIndex = (this.activeIndex - 1 + this.totalSlides) % this.totalSlides;
        this.goToIndex(prevIndex);
    }
    
    updateSlides() {
        // Update slide classes
        this.slides.forEach((slide, index) => {
            slide.classList.remove('active', 'prev', 'next', 'hidden');
            
            if (index === this.activeIndex) {
                slide.classList.add('active');
            } else if (index === (this.activeIndex - 1 + this.totalSlides) % this.totalSlides) {
                slide.classList.add('prev');
            } else if (index === (this.activeIndex + 1) % this.totalSlides) {
                slide.classList.add('next');
            } else {
                slide.classList.add('hidden');
            }
        });
        
        // Update title
        const activeSlide = this.slides[this.activeIndex];
        const title = activeSlide.getAttribute('data-title');
        if (this.titleElement && title) {
            this.titleElement.textContent = title;
        }
        
        // Update step dots
        this.stepDots.forEach((dot, index) => {
            dot.classList.toggle('active', index === this.activeIndex);
        });
        
        // Dispatch custom event for chart resizing
        document.dispatchEvent(new CustomEvent('carouselChange', {
            detail: { activeIndex: this.activeIndex }
        }));
    }
}

// Initialize when DOM is ready
let tableCarousel = null;

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('tableCarousel')) {
        tableCarousel = new TableCarousel();
    }
});
