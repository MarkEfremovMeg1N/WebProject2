// Слайдер отзывов
document.addEventListener('DOMContentLoaded', function() {
  const testimonialSlider = document.querySelector('.testimonial-slider');
  
  if (!testimonialSlider) return;
  
  // Элементы слайдов и навигации
  const slides = document.querySelectorAll('.testimonial-slide');
  const cardContent = document.querySelector('.testimonial-card-content');
  const prevBtn = document.querySelector('.nav-arrow.prev');
  const nextBtn = document.querySelector('.nav-arrow.next');
  const counter = document.querySelector('.slide-counter');
  
  let currentSlide = 0;
  const totalSlides = slides.length;
  
  // Функция для обновления счетчика
  function updateCounter() {
    const currentNumber = (currentSlide + 1).toString().padStart(2, '0');
    const totalNumber = totalSlides.toString().padStart(2, '0');
    if (counter) {
      counter.textContent = `${currentNumber} / ${totalNumber}`;
    }
  }
  
  // Функция для обновления контента карточки
  function updateCardContent() {
    if (!cardContent) return;
    
    const activeSlide = slides[currentSlide];
    if (!activeSlide) return;
    
    // Копируем контент из активного слайда в карточку
    const slideContent = activeSlide.querySelector('.testimonial-content');
    if (slideContent) {
      // Сохраняем текущие отступы и стили
      const currentPadding = window.getComputedStyle(cardContent).padding;
      const currentDisplay = window.getComputedStyle(cardContent).display;
      
      // Заменяем весь контент
      cardContent.innerHTML = slideContent.innerHTML;
      
      // Восстанавливаем отступы и стили
      cardContent.style.padding = currentPadding;
      cardContent.style.display = currentDisplay;
      
      // Анимация появления
      cardContent.style.opacity = '0';
      setTimeout(() => {
        cardContent.style.transition = 'opacity 0.3s ease';
        cardContent.style.opacity = '1';
      }, 50);
    }
  }
  
  // Функция для переключения слайдов
  function goToSlide(index) {
    // Корректируем индекс, если он выходит за границы
    if (index >= totalSlides) {
      currentSlide = 0;
    } else if (index < 0) {
      currentSlide = totalSlides - 1;
    } else {
      currentSlide = index;
    }
    
    // Обновляем активные классы у слайдов (для визуального выделения в DOM)
    slides.forEach((slide, i) => {
      slide.classList.toggle('active', i === currentSlide);
    });
    
    // Обновляем контент и счетчик
    updateCardContent();
    updateCounter();
  }
  
  // Следующий слайд
  function nextSlide() {
    goToSlide(currentSlide + 1);
  }
  
  // Предыдущий слайд
  function prevSlide() {
    goToSlide(currentSlide - 1);
  }
  
  // Инициализация слайдера
  function initSlider() {
    if (totalSlides === 0) return;
    
    // Устанавливаем начальный слайд
    goToSlide(0);
    
    // Обработчики событий для кнопок
    if (prevBtn) {
      prevBtn.addEventListener('click', prevSlide);
    }
    
    if (nextBtn) {
      nextBtn.addEventListener('click', nextSlide);
    }
    
    // Добавляем поддержку клавиатуры
    document.addEventListener('keydown', function(e) {
      // Проверяем, находится ли фокус внутри слайдера (опционально)
      const isSliderFocused = testimonialSlider.contains(document.activeElement);
      
      switch(e.key) {
        case 'ArrowLeft':
          e.preventDefault();
          prevSlide();
          break;
        case 'ArrowRight':
          e.preventDefault();
          nextSlide();
          break;
      }
    });
    
    // Добавляем свайп для мобильных устройств
    let touchStartX = 0;
    let touchEndX = 0;
    
    testimonialSlider.addEventListener('touchstart', function(e) {
      touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    
    testimonialSlider.addEventListener('touchend', function(e) {
      touchEndX = e.changedTouches[0].screenX;
      handleSwipe();
    }, { passive: true });
    
    function handleSwipe() {
      const swipeThreshold = 50;
      const diff = touchStartX - touchEndX;
      
      if (Math.abs(diff) > swipeThreshold) {
        if (diff > 0) {
          // Свайп влево - следующий слайд
          nextSlide();
        } else {
          // Свайп вправо - предыдущий слайд
          prevSlide();
        }
      }
    }
    
    // Автоматическое перелистывание (опционально)
    let autoSlideInterval = setInterval(nextSlide, 5000);
    
    // Останавливаем автопрокрутку при наведении
    testimonialSlider.addEventListener('mouseenter', () => {
      clearInterval(autoSlideInterval);
    });
    
    // Возобновляем автопрокрутку при уходе мыши
    testimonialSlider.addEventListener('mouseleave', () => {
      autoSlideInterval = setInterval(nextSlide, 5000);
    });
  }
  
  // Инициализируем слайдер
  initSlider();
});