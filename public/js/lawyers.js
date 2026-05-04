(() => {
  const { useEffect, useState } = React;

  const sampleLawyers = [
    {
      id: 901,
      name: 'Maya Santos',
      specialization: 'Corporate Law',
      status: 'active',
      rating: 5,
      reviewsCount: 18,
      barRoll: 'BAR-2021-10452',
      bio: 'Corporate counsel focused on contracts, governance, and fast-moving commercial work with calm, practical guidance.',
      avatarUrl: '',
      appointUrl: '#',
      viewProfileUrl: '#',
      canAppoint: true,
      review: { rating: 0, comment: '' },
    },
    {
      id: 902,
      name: 'Ethan Reyes',
      specialization: 'Litigation',
      status: 'inactive',
      rating: 4.7,
      reviewsCount: 0,
      barRoll: 'BAR-2019-08731',
      bio: 'Trial lawyer with a sharp eye for dispute strategy, evidence review, and client-friendly communication.',
      avatarUrl: '',
      appointUrl: '#',
      viewProfileUrl: '#',
      canAppoint: false,
      review: { rating: 0, comment: '' },
    },
    {
      id: 903,
      name: 'Sofia Tan',
      specialization: 'Family Law',
      status: 'active',
      rating: 4.9,
      reviewsCount: 6,
      barRoll: 'BAR-2020-22118',
      bio: 'Supportive family law attorney who balances empathy, precision, and strong case organization.',
      avatarUrl: '',
      appointUrl: '#',
      viewProfileUrl: '#',
      canAppoint: true,
      review: { rating: 0, comment: '' },
    },
  ];

  const pageDataNode = document.getElementById('lawyers-app-data');
  const mountNode = document.getElementById('lawyers-app');
  if (!pageDataNode || !mountNode || !window.ReactDOM) {
    return;
  }

  const pageData = JSON.parse(pageDataNode.textContent || '{}');
  const initialLawyers = Array.isArray(pageData.lawyers) && pageData.lawyers.length > 0
    ? pageData.lawyers
    : sampleLawyers;

  const formatRating = (value) => Number(value || 0).toFixed(1);
  const normalizeStatus = (value) => String(value || 'inactive').toLowerCase();
  const initialsFromName = (name) => {
    const compact = String(name || 'LW').replace(/\s+/g, '');
    return (compact.slice(0, 2) || 'LW').toUpperCase();
  };

  function RatingStars({
    value,
    max = 5,
    interactive = false,
    highlightedValue = null,
    onSelect = null,
    onHover = null,
    onClear = null,
    ariaLabel = 'Rating',
  }) {
    const activeValue = highlightedValue ?? value;

    return (
      <div
        className={`rating-stars ${interactive ? 'is-interactive' : 'is-readonly'}`}
        role={interactive ? 'radiogroup' : 'img'}
        aria-label={ariaLabel}
      >
        {Array.from({ length: max }, (_, index) => {
          const starValue = index + 1;
          const filled = starValue <= activeValue;
          return interactive ? (
            <button
              key={starValue}
              type="button"
              className={`rating-star ${filled ? 'is-filled' : ''}`}
              onClick={() => onSelect?.(starValue)}
              onMouseEnter={() => onHover?.(starValue)}
              onFocus={() => onHover?.(starValue)}
              onMouseLeave={() => onClear?.()}
              onBlur={() => onClear?.()}
              aria-label={`Rate ${starValue} star${starValue === 1 ? '' : 's'}`}
              aria-pressed={value === starValue}
            >
              {"\u2605"}
            </button>
          ) : (
            <span
              key={starValue}
              className={`rating-star ${filled ? 'is-filled' : ''}`}
              aria-hidden="true"
            >
              {"\u2605"}
            </span>
          );
        })}
      </div>
    );
  }

  function ReviewForm({ lawyer, csrfToken, reviewEndpoint, onSaved, demoMode = false, onClose = null, showHeader = true }) {
    const [rating, setRating] = useState(lawyer.review?.rating || 0);
    const [comment, setComment] = useState(lawyer.review?.comment || '');
    const [hoverRating, setHoverRating] = useState(null);
    const [status, setStatus] = useState(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    useEffect(() => {
      setRating(lawyer.review?.rating || 0);
      setComment(lawyer.review?.comment || '');
      setHoverRating(null);
      setIsSubmitting(false);
    }, [lawyer.id, lawyer.review?.rating, lawyer.review?.comment]);

    const submitLabel = (lawyer.review?.rating || 0) > 0 ? 'Update Review' : 'Save Review';

    const handleSubmit = async (event) => {
      event.preventDefault();
      if (demoMode) {
        setStatus({ type: 'error', message: 'Preview profiles cannot save reviews yet.' });
        return;
      }
      if (!rating) {
        setStatus({ type: 'error', message: 'Select a rating before saving your review.' });
        return;
      }

      setIsSubmitting(true);
      setStatus(null);

      try {
        const response = await fetch(reviewEndpoint, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: new URLSearchParams({
            action: 'review',
            lawyer_id: String(lawyer.id),
            rating: String(rating),
            comment,
            csrf_token: csrfToken,
          }).toString(),
        });

        const data = await response.json();
        if (!response.ok || !data.ok) {
          throw new Error(data.message || 'Unable to save the review right now.');
        }

        if (data.lawyer) {
          onSaved?.(data.lawyer, data.message || 'Review saved.');
        }
        setStatus({ type: 'success', message: data.message || 'Review saved.' });
        if (onClose) {
          window.setTimeout(() => onClose(), 150);
        }
      } catch (error) {
        setStatus({ type: 'error', message: error.message || 'Unable to save the review right now.' });
      } finally {
        setIsSubmitting(false);
      }
    };

    return (
      <form className="review-form" data-no-loading onSubmit={handleSubmit}>
        <input type="hidden" name="lawyer_id" value={lawyer.id} />
        {showHeader ? (
          <div className="review-form__header">
            <div>
              <h4>Rating & Review</h4>
              <p>Share feedback about professionalism, communication, or results.</p>
            </div>
            <span className="review-form__meta">
              {lawyer.reviewsCount > 0 ? `${lawyer.reviewsCount} review${lawyer.reviewsCount === 1 ? '' : 's'}` : 'No reviews yet'}
            </span>
          </div>
        ) : null}

        <div className="review-form__rating">
          <RatingStars
            value={rating}
            interactive
            highlightedValue={hoverRating ?? rating}
            onSelect={(nextValue) => {
              setRating(nextValue);
              setStatus(null);
            }}
            onHover={setHoverRating}
            onClear={() => setHoverRating(null)}
            ariaLabel={`Rate ${lawyer.name}`}
          />
          <span className="review-form__rating-label">
            {rating ? `${rating}.0 out of 5 selected` : 'Tap a star to choose a rating'}
          </span>
        </div>

        <label className="review-form__field">
          <span className="sr-only">Comment</span>
          <textarea
            rows="4"
            value={comment}
            onChange={(event) => {
              setComment(event.target.value);
              setStatus(null);
            }}
            placeholder="Share feedback about professionalism, communication, or results."
          />
        </label>

        <div className="review-form__footer">
          <button
            type="submit"
            className="button button-primary review-form__submit"
            disabled={!rating || isSubmitting || demoMode}
          >
            {demoMode ? 'Preview only' : isSubmitting ? 'Saving...' : submitLabel}
          </button>
          {status ? (
            <div className={`inline-status inline-status--${status.type}`} role="status" aria-live="polite">
              {status.message}
            </div>
          ) : null}
        </div>
      </form>
    );
  }

  function ReviewModal({ isOpen, lawyer, csrfToken, reviewEndpoint, onSaved, onClose, demoMode = false }) {
    useEffect(() => {
      if (!isOpen) {
        return;
      }

      const onKeyDown = (event) => {
        if (event.key === 'Escape') {
          onClose?.();
        }
      };

      document.addEventListener('keydown', onKeyDown);
      document.body.classList.add('has-modal-open');

      return () => {
        document.removeEventListener('keydown', onKeyDown);
        document.body.classList.remove('has-modal-open');
      };
    }, [isOpen, onClose]);

    if (!isOpen) {
      return null;
    }

    return (
      <div
        className="review-modal"
        role="presentation"
        onMouseDown={(event) => {
          if (event.target === event.currentTarget) {
            onClose?.();
          }
        }}
      >
        <div
          className="review-modal__dialog"
          role="dialog"
          aria-modal="true"
          aria-labelledby={`review-modal-title-${lawyer.id}`}
        >
          <div className="review-modal__header">
            <div>
              <h4 id={`review-modal-title-${lawyer.id}`}>{(lawyer.review?.rating || 0) > 0 ? 'Update Review' : 'Rating & Review'}</h4>
              <p>Share feedback about professionalism, communication, or results.</p>
            </div>
            <button type="button" className="review-modal__close" onClick={() => onClose?.()} aria-label="Close review form">
              {"\u2715"}
            </button>
          </div>

          <ReviewForm
            lawyer={lawyer}
            csrfToken={csrfToken}
            reviewEndpoint={reviewEndpoint}
            onSaved={onSaved}
            demoMode={demoMode}
            onClose={onClose}
            showHeader={false}
          />
        </div>
      </div>
    );
  }

  function LawyerCard({ lawyer, csrfToken, reviewEndpoint, onReviewSaved, demoMode = false }) {
    const status = normalizeStatus(lawyer.status);
    const nameInitials = initialsFromName(lawyer.name);
    const reviewValue = Number(lawyer.rating || 0);
    const hasReviews = (lawyer.reviewsCount || 0) > 0;
    const [isReviewOpen, setIsReviewOpen] = useState(false);
    const reviewButtonLabel = hasReviews ? 'Update Review' : 'Rate & Review';
    const openReview = () => setIsReviewOpen(true);
    const handleCardActivation = (event) => {
      const interactiveTarget = event.target.closest('a, button, input, textarea, select, [role="button"]');
      if (interactiveTarget && interactiveTarget !== event.currentTarget) {
        return;
      }
      openReview();
    };
    const handleCardKeyDown = (event) => {
      if (event.target !== event.currentTarget) {
        return;
      }
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openReview();
      }
    };

    return (
      <article
        className="lawyer-card"
        tabIndex={0}
        role="button"
        aria-haspopup="dialog"
        aria-expanded={isReviewOpen ? 'true' : 'false'}
        aria-label={`Open review form for ${lawyer.name}`}
        onClick={handleCardActivation}
        onKeyDown={handleCardKeyDown}
      >
        <header className="lawyer-card__header">
          <div className="lawyer-card__identity">
            <div className="lawyer-card__avatar">
              {lawyer.avatarUrl ? (
                <img src={lawyer.avatarUrl} alt={`Avatar for ${lawyer.name}`} />
              ) : (
                <span>{nameInitials}</span>
              )}
            </div>
            <div>
              <h3>{lawyer.name}</h3>
              <p>{lawyer.specialization}</p>
            </div>
          </div>
          <span className={`status-badge is-${status}`}>{status}</span>
        </header>

        <div className="lawyer-card__stats">
          <button
            type="button"
            className="lawyer-card__rating lawyer-card__rating-trigger"
            onClick={openReview}
            aria-haspopup="dialog"
            aria-expanded={isReviewOpen ? 'true' : 'false'}
            aria-label={`${reviewButtonLabel} for ${lawyer.name}`}
          >
            <span className="lawyer-card__label">Rating</span>
            <strong>{formatRating(reviewValue)}/5</strong>
            <RatingStars
              value={reviewValue}
              ariaLabel={`${lawyer.name} average rating ${formatRating(reviewValue)} out of 5`}
            />
            <span className="lawyer-card__hint">{reviewButtonLabel}</span>
          </button>

          <div>
            <span className="lawyer-card__label">Reviews</span>
            <strong>{lawyer.reviewsCount.toLocaleString()}</strong>
            <span className="lawyer-card__hint">{hasReviews ? 'Community feedback' : 'No reviews yet'}</span>
          </div>

          <div>
            <span className="lawyer-card__label">Bar Roll Number</span>
            <strong>{lawyer.barRoll}</strong>
            <span className="lawyer-card__hint">Professional registration</span>
          </div>
        </div>

        <p className="lawyer-card__bio">{lawyer.bio}</p>

        <div className="lawyer-card__actions">
          {lawyer.canAppoint && !demoMode ? (
            <a className="button button-primary" href={lawyer.appointUrl}>
              Appoint
            </a>
          ) : (
            <button
              className="button button-primary"
              type="button"
              disabled
              aria-disabled="true"
              title={demoMode ? 'Preview profiles are not linked yet' : 'This lawyer is inactive'}
            >
              Appoint
            </button>
          )}
          {demoMode ? (
            <button className="button button-secondary" type="button" disabled aria-disabled="true" title="Preview profiles are not linked yet">
              View Profile
            </button>
          ) : (
            <a className="button button-secondary" href={lawyer.viewProfileUrl}>
              View Profile
            </a>
          )}
        </div>

        <ReviewModal
          isOpen={isReviewOpen}
          lawyer={lawyer}
          csrfToken={csrfToken}
          reviewEndpoint={reviewEndpoint}
          onSaved={(updatedLawyer, message) => {
            onReviewSaved(updatedLawyer, message);
            setIsReviewOpen(false);
          }}
          onClose={() => setIsReviewOpen(false)}
          demoMode={demoMode}
        />
      </article>
    );
  }

  function LawyerList() {
    const [lawyers, setLawyers] = useState(initialLawyers);
    const [toast, setToast] = useState(null);

    useEffect(() => {
      if (!toast) {
        return;
      }
      const timer = window.setTimeout(() => setToast(null), 3600);
      return () => window.clearTimeout(timer);
    }, [toast]);

    const handleReviewSaved = (updatedLawyer, message) => {
      setLawyers((current) =>
        current.map((lawyer) => (lawyer.id === updatedLawyer.id ? updatedLawyer : lawyer))
      );
      setToast({ type: 'success', message });
    };

    const hasLiveLawyers = Array.isArray(pageData.lawyers) && pageData.lawyers.length > 0;
    const showSampleNote = !hasLiveLawyers && pageData.useSampleFallback;

    return (
      <div className="lawyer-directory-shell">
        {showSampleNote ? (
          <div className="inline-banner" role="status">
            Showing sample lawyer profiles until live records are available.
          </div>
        ) : null}

        {toast ? (
          <div className={`toast toast-${toast.type}`} role="status" aria-live="polite">
            {toast.message}
          </div>
        ) : null}

        <div className="lawyer-directory-grid">
          {lawyers.map((lawyer) => (
            <LawyerCard
              key={lawyer.id}
              lawyer={lawyer}
              csrfToken={pageData.csrfToken}
              reviewEndpoint={pageData.reviewEndpoint}
              onReviewSaved={handleReviewSaved}
              demoMode={showSampleNote}
            />
          ))}
        </div>
      </div>
    );
  }

  ReactDOM.createRoot(mountNode).render(<LawyerList />);
})();
