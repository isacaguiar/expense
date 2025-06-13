package com.novemax.expense.service;

import java.math.BigDecimal;
import java.time.YearMonth;
import java.util.Map;
import java.util.UUID;

public interface BalanceService {
  Map<String, Map<String, BigDecimal>> calculateBalances(UUID groupId, YearMonth month);

  Map<YearMonth, Map<String, Map<String, BigDecimal>>> calculateMonthlyBalances(UUID groupId);
}

