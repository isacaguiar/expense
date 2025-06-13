package com.novemax.expense.dto;

import jakarta.validation.constraints.Email;
import jakarta.validation.constraints.NotBlank;
import lombok.Data;

@Data
public class UserEmailDTO {
  @Email
  @NotBlank
  private String email;
}
